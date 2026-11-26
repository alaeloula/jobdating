<?php

namespace Tests\Feature\Companies;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyImport;
use App\Models\Event;
use App\Models\EventCompany;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Companies\CompanyService;
use App\Services\Imports\CompanyImportRunner;
use App\Services\Imports\CompanyWorkbookReader;
use App\Support\CompanyNameKey;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FakeWorkbook;
use Tests\TestCase;

/**
 * Import des entreprises (ADM-04, T-64, plan v1.3). Classeurs FICTIFS uniquement : la structure
 * reprend celle du classeur de suivi (feuille « Entreprises Invitées », colonnes D et H).
 */
class CompanyImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Signature du contrat', 'Contrat préparé Oui/Non', 'NOK', 'Entreprises', 'Contact STAFF CME', 'Appel téléphonique', 'Envoie du mail d\'invitation', 'Confirmation par mail'];

    /** Valeurs des colonnes ignorées : ne doivent jamais être décodées ni apparaître nulle part. */
    private const CONTACT = 'ContactSentinelleFictif';

    private const PASSWORD = 'MotDePasseSentinelle';

    private User $admin;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Queue::fake();
        $this->admin = User::factory()->withRole(Role::Administrateur)->create();
        $date = now(Event::TIMEZONE)->addMonth()->toDateString();
        $this->event = Event::query()->forceCreate(['name' => 'JobDating', 'school_year' => Event::schoolYearFor($date), 'date' => $date, 'location' => 'Campus Fictif']);
        $this->withHeader('Origin', 'http://localhost');
    }

    /**
     * Ligne du classeur : nom en D, contact en E, confirmation en H.
     *
     * @return array<int, mixed>
     */
    private static function row(mixed $name, mixed $confirmation = 'Oui', mixed $contact = self::CONTACT): array
    {
        return [0 => null, 1 => 'Non', 2 => null, 3 => $name, 4 => $contact, 5 => null, 6 => 'Oui', 7 => $confirmation];
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows  numéro de ligne => cellules
     */
    private function workbook(array $rows, ?array $headers = null, string $sheet = 'Entreprises Invitées'): FakeWorkbook
    {
        return new FakeWorkbook([
            'Planning Journée J' => [1 => ['Horaire'], 2 => ['09:00']],
            $sheet => [1 => $headers ?? self::HEADERS] + $rows,
            'Informations entreprises Appli' => [1 => ['Nom de l\'entreprise', 'Mot de passe application jobdating'], 2 => ['Fictive', self::PASSWORD]],
        ], ['ChaineInutiliseeSentinelle']);
    }

    private function preview(UploadedFile $file, ?int $eventId = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->admin, 'web')->post('/api/v1/company-imports/preview', [
            'file' => $file, 'event_id' => $eventId ?? $this->event->id,
        ], ['Accept' => 'application/json']);
    }

    private function confirm(UploadedFile $file, string $fingerprint, ?string $key = null): TestResponse
    {
        return $this->actingAs($this->admin, 'web')->post('/api/v1/company-imports', [
            'file' => $file, 'event_id' => $this->event->id, 'fingerprint' => $fingerprint, 'confirmation_key' => $key ?? (string) Str::uuid(),
        ], ['Accept' => 'application/json']);
    }

    private function import(FakeWorkbook $book): CompanyImport
    {
        $fingerprint = $this->preview($book->file())->assertOk()->json('data.fingerprint');
        $id = (int) $this->confirm($book->file(), $fingerprint)->assertCreated()->json('data.id');
        app(CompanyImportRunner::class)->run($id);

        return CompanyImport::query()->findOrFail($id);
    }

    // ---------------------------------------------------------------- Lecture

    public function test_apercu_lignes_confirmees_doublons_et_existantes(): void
    {
        Company::query()->forceCreate([
            'user_id' => User::factory()->withRole(Role::Entreprise)->create(['email' => null, 'username' => 'deja-la'])->id,
            'name' => 'Société Déjà Là', 'name_key' => CompanyNameKey::of('Société Déjà Là'), 'credentials_version' => 1,
        ]);
        $book = $this->workbook([
            2 => self::row('Acme Fictive'),
            3 => self::row('Beta Fictive', ' oui '),
            4 => self::row('Gamma Fictive', 'Non'),
            5 => self::row('Delta Fictive', null),
            6 => self::row('ACME-fictive !'),
            7 => self::row('SOCIETE deja la'),
            8 => self::row('...'),
            9 => self::row(str_repeat('a', 151)),
            11 => self::row('Acme'),
            12 => self::row('Acme', 'Non'),
            13 => self::row('Epsilon Fictive', 'Non'),
            14 => self::row('Epsilon Fictive'),
        ]);

        $data = $this->preview($book->file())->assertOk()->json('data');

        $this->assertSame(['create' => 4, 'existing' => 1, 'duplicate' => 1, 'reject' => 2, 'unconfirmed' => 4], $data['counts']);
        $this->assertSame([
            ['line' => 2, 'name' => 'Acme Fictive', 'username' => 'acme-fictive'],
            ['line' => 3, 'name' => 'Beta Fictive', 'username' => 'beta-fictive'],
            ['line' => 11, 'name' => 'Acme', 'username' => 'acme'],
            ['line' => 14, 'name' => 'Epsilon Fictive', 'username' => 'epsilon-fictive'],
        ], $data['to_create']);
        $this->assertSame([
            ['line' => 6, 'reason' => 'Doublon dans le fichier (ligne 2)', 'name' => 'ACME-fictive !'],
            ['line' => 7, 'reason' => 'Entreprise déjà existante : ajoutez-la depuis l’événement', 'name' => 'SOCIETE deja la'],
            ['line' => 8, 'reason' => 'Nom sans lettre ni chiffre', 'name' => null],
            ['line' => 9, 'reason' => 'Nom manquant ou trop long', 'name' => null],
        ], $data['signaled']);
        $this->assertSame(0, Company::query()->where('name', 'Acme Fictive')->count());
    }

    public function test_noms_d_utilisateur_reserves_dans_le_lot(): void
    {
        $long = str_repeat('Entreprise ', 6).'Alpha';
        $book = $this->workbook([2 => self::row('Acme & Co'), 3 => self::row('Acme Co.'), 4 => self::row('Acme-Co Maroc'), 5 => self::row($long), 6 => self::row($long.' Bis')]);

        $users = array_column($this->preview($book->file())->assertOk()->json('data.to_create'), 'username');

        // « Acme Co. » est un doublon de « Acme & Co » (même nom simplifié) ; les deux noms longs ont la
        // même proposition une fois coupée à 50 caractères : la seconde reçoit un suffixe.
        $this->assertSame('acme-co', $users[0]);
        $this->assertSame('acme-co-maroc', $users[1]);
        $this->assertSame(substr($users[2], 0, 48), substr($users[3], 0, 48));
        $this->assertStringEndsWith('-2', $users[3]);
        $this->assertCount(4, array_unique($users));
        foreach ($users as $username) {
            $this->assertLessThanOrEqual(50, strlen($username));
        }
    }

    public function test_sentinelles_jamais_decodees(): void
    {
        $book = $this->workbook([
            2 => self::row('Acme Fictive'),
            3 => self::row('Beta Fictive', 'Oui', ['inline' => 'ContactEnLigneSentinelle']),
        ]);
        $file = $book->file();
        $reader = new CompanyWorkbookReader;
        $rows = $reader->read((string) $file->getRealPath(), 'suivi.xlsx');

        foreach ([self::CONTACT, self::PASSWORD, 'ChaineInutiliseeSentinelle', 'Horaire', '09:00', 'Non'] as $sentinel) {
            $this->assertNotContains($book->indexOf($sentinel), $reader->decodedSharedStrings, $sentinel);
        }
        $this->assertSame([['line' => 2, 'name' => 'Acme Fictive', 'confirmation' => 'Oui'], ['line' => 3, 'name' => 'Beta Fictive', 'confirmation' => 'Oui']], $rows);

        // Contenu DÉCHIFFRÉ du `payload` (avant traitement) et des identifiants (après) : liste blanche.
        $book = $this->workbook([2 => self::row('Acme Fictive', 'Oui', ['inline' => 'ContactEnLigneSentinelle'])]);
        $id = (int) $this->confirm($book->file(), $this->preview($book->file())->json('data.fingerprint'))->assertCreated()->json('data.id');
        $payload = CompanyImport::query()->findOrFail($id)->payload;
        $this->assertSame([['line' => 2, 'name' => 'Acme Fictive', 'key' => CompanyNameKey::of('Acme Fictive'), 'username' => 'acme-fictive']], $payload['rows'] ?? null);
        app(CompanyImportRunner::class)->run($id);
        $import = CompanyImport::query()->findOrFail($id);
        $this->assertSame(['company_id', 'user_id', 'name', 'username', 'password', 'version', 'hash'], array_keys($import->credentials[0] ?? []));
        $stored = json_encode([CompanyImport::query()->get()->toArray(), $import->credentials, Company::query()->get()->toArray(), AuditLog::query()->get()->toArray()]);
        foreach ([self::CONTACT, self::PASSWORD, 'ContactEnLigneSentinelle'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, (string) $stored);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function confirmations(): iterable
    {
        yield 'Oui' => ['Oui', true];
        yield 'casse et espaces' => [' oUI  ', true];
        yield 'espace insécable' => ["Oui\u{00A0}", true];
        yield 'Non' => ['Non', false];
        yield 'Oui ?' => ['Oui ?', false];
        yield 'ponctuée' => ['O!u!i', false];
        yield 'Oui.' => ['Oui.', false];
    }

    // Revue Codex T-64, constat 2.
    #[DataProvider('confirmations')]
    public function test_confirmation_sans_casse_ni_espaces_seulement(string $value, bool $created): void
    {
        $counts = $this->preview($this->workbook([2 => self::row('Acme Fictive', $value)])->file())->assertOk()->json('data.counts');

        $this->assertSame($created ? 1 : 0, $counts['create']);
        $this->assertSame($created ? 0 : 1, $counts['unconfirmed']);
    }

    public function test_variantes_de_cellules(): void
    {
        $book = $this->workbook([
            2 => self::row(['rich' => ['Acme ', 'Fictive']]),
            3 => self::row(['inline' => 'Beta Fictive'], ['inline' => 'Oui']),
            4 => self::row(['formula' => 'A1', 'value' => 'Gamma']),
            5 => self::row(['number' => 1337]),
            6 => self::row('Delta Fictive', ['formula' => 'B1', 'value' => 'Oui']),
            9 => self::row('Zeta Fictive'),
        ]);

        $data = $this->preview($book->file())->assertOk()->json('data');

        $this->assertSame(['Acme Fictive', 'Beta Fictive', 'Zeta Fictive'], array_column($data['to_create'], 'name'));
        $this->assertSame([9], array_values(array_filter(array_column($data['to_create'], 'line'), fn ($l) => $l === 9)));
        $this->assertSame([4, 5, 6], array_column($data['signaled'], 'line'));
        $this->assertStringContainsString('Valeur non admise', $data['signaled'][0]['reason']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badWorkbooks(): iterable
    {
        yield 'feuille absente' => [['sheet' => 'Autre feuille'], 'Feuille « Entreprises Invitées » introuvable'];
        yield 'colonne absente' => [['headers' => ['Entreprises', 'Contact']], 'Colonne « Confirmation par mail » introuvable'];
        yield 'colonne en double' => [['headers' => ['Entreprises', 'Confirmation par mail', 'ENTREPRISES']], 'apparaît plusieurs fois'];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('badWorkbooks')]
    public function test_classeur_refuse(array $options, string $message): void
    {
        $book = $this->workbook([2 => self::row('Acme Fictive')], $options['headers'] ?? null, $options['sheet'] ?? 'Entreprises Invitées');

        $response = $this->preview($book->file())->assertStatus(422);
        $this->assertStringContainsString($message, (string) $response->json('message'));
    }

    public function test_feuilles_en_double_et_fichier_non_xlsx(): void
    {
        $book = new FakeWorkbook(['Entreprises Invitées' => [1 => self::HEADERS, 2 => self::row('A')], 'ENTREPRISES  invitees' => [1 => self::HEADERS]]);
        $this->preview($book->file())->assertStatus(422)->assertJsonPath('message', 'Plusieurs feuilles portent le nom « Entreprises Invitées » : gardez-en une seule.');

        $csv = UploadedFile::fake()->createWithContent('suivi.csv', "Entreprises,Confirmation par mail\nAcme,Oui\n");
        $this->preview($csv)->assertStatus(422)->assertJsonValidationErrors('file');
    }

    // ---------------------------------------------------------------- Traitement

    public function test_import_complet_comptes_participations_et_fiches(): void
    {
        $import = $this->import($this->workbook([2 => self::row('Acme Fictive'), 3 => self::row('Beta Fictive'), 4 => self::row('Gamma', 'Non')]));

        $this->assertSame(CompanyImport::DONE, $import->status);
        $acme = Company::query()->with('user')->where('name', 'Acme Fictive')->firstOrFail();
        $this->assertSame(CompanyNameKey::of('Acme Fictive'), $acme->name_key);
        $this->assertSame('acme-fictive', $acme->user->username);
        $this->assertNull($acme->user->email);
        $this->assertSame(Role::Entreprise, $acme->user->currentRole());
        $this->assertSame(2, EventCompany::query()->where('event_id', $this->event->id)->count());
        $this->assertSame(2, AuditLog::query()->where('action', AuditAction::CompanyCreated->value)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::CompanyImportCompleted->value)->count());
        $this->assertNull($import->getRawOriginal('payload'));

        $sheets = $this->actingAs($this->admin, 'web')->getJson("/api/v1/company-imports/{$import->id}/credentials")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertCount(2, $sheets['sheets']);
        $first = $sheets['sheets'][0];
        $this->assertTrue(Hash::check($first['password'], (string) User::query()->where('username', $first['username'])->value('password')));
        $this->assertArrayNotHasKey('hash', $first);
        $this->assertSame($this->event->fullName(), $first['event']['name']);

        $this->getJson("/api/v1/company-imports/{$import->id}")->assertOk()->assertJsonMissingPath('data.credentials')->assertJsonMissingPath('data.payload');
    }

    public function test_confirmation_idempotente_et_apercu_change(): void
    {
        $book = $this->workbook([2 => self::row('Acme Fictive')]);
        $fingerprint = $this->preview($book->file())->json('data.fingerprint');
        $key = (string) Str::uuid();

        $id = $this->confirm($book->file(), $fingerprint, $key)->assertCreated()->json('data.id');
        $this->confirm($book->file(), $fingerprint, $key)->assertOk()->assertJsonPath('data.id', $id);
        $this->confirm($book->file(), str_repeat('a', 64), $key)->assertStatus(409)->assertJsonPath('code', 'confirmation_key_reused');
        $this->confirm($this->workbook([2 => self::row('Autre Fictive')])->file(), $fingerprint)->assertStatus(409);
        $this->assertSame(1, CompanyImport::query()->count());

        $none = $this->workbook([2 => self::row('Acme Fictive', 'Non')]);
        $this->confirm($none->file(), $this->preview($none->file())->json('data.fingerprint'))->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_nom_pris_ou_evenement_archive_avant_le_traitement(): void
    {
        $book = $this->workbook([2 => self::row('Acme Fictive')]);
        $id = (int) $this->confirm($book->file(), $this->preview($book->file())->json('data.fingerprint'))->assertCreated()->json('data.id');
        app(CompanyService::class)->create(['name' => 'ACME fictive', 'username' => 'autre-acme'], null, $this->admin, ['event_id' => $this->event->id]);

        app(CompanyImportRunner::class)->run($id);

        $this->assertSame(CompanyImport::FAILED, CompanyImport::query()->findOrFail($id)->status);
        $this->assertSame(CompanyImportRunner::CONFLICT_ERROR, CompanyImport::query()->findOrFail($id)->error);
        $this->assertNull(User::query()->where('username', 'acme-fictive')->first());

        $second = $this->workbook([2 => self::row('Beta Fictive')]);
        $other = (int) $this->confirm($second->file(), $this->preview($second->file())->json('data.fingerprint'))->assertCreated()->json('data.id');
        $this->event->forceFill(['archived_at' => now()])->save();
        app(CompanyImportRunner::class)->run($other);
        $this->assertSame(CompanyImport::FAILED, CompanyImport::query()->findOrFail($other)->status);
        $this->assertSame(0, Company::query()->where('name', 'Beta Fictive')->count());
    }

    public function test_echec_au_milieu_du_lot_ne_laisse_rien(): void
    {
        $book = $this->workbook([2 => self::row('Acme Fictive'), 3 => self::row('Beta Fictive')]);
        $id = (int) $this->confirm($book->file(), $this->preview($book->file())->json('data.fingerprint'))->assertCreated()->json('data.id');
        $created = 0;
        $this->app->instance(AuditLogger::class, new class($created) extends AuditLogger
        {
            public function __construct(private int &$count) {}

            public function record(AuditAction $action, ?Model $subject = null, array $old = [], array $new = [], ?User $actor = null, bool $visitor = false): AuditLog
            {
                if ($action === AuditAction::CompanyCreated && ++$this->count === 2) {
                    throw new RuntimeException('Échec simulé');
                }

                return parent::record($action, $subject, $old, $new, $actor, $visitor);
            }
        });

        app(CompanyImportRunner::class)->run($id);

        $import = CompanyImport::query()->findOrFail($id);
        $this->assertSame(CompanyImport::FAILED, $import->status);
        $this->assertSame(CompanyImportRunner::TECHNICAL_ERROR, $import->error);
        $this->assertSame(0, Company::query()->count());
        $this->assertSame(0, EventCompany::query()->count());
        $this->assertSame(0, User::query()->whereIn('username', ['acme-fictive', 'beta-fictive'])->count());
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::CompanyCreated->value)->count());
        $this->assertNull($import->getRawOriginal('credentials'));
    }

    // ---------------------------------------------------------------- Fiches

    public function test_validite_des_fiches(): void
    {
        $import = $this->import($this->workbook([
            2 => self::row('Alpha Fictive'), 3 => self::row('Beta Fictive'), 4 => self::row('Gamma Fictive'), 5 => self::row('Delta Fictive'), 6 => self::row('Epsilon Fictive'),
        ]));
        $companies = Company::query()->with('user')->get()->keyBy('name');

        // Beta change son mot de passe ; Gamma est réinitialisée ; Delta est désactivée ; Epsilon est retirée.
        $companies['Beta Fictive']->user->forceFill(['password' => 'NouveauMotDePasse1'])->save();
        app(CompanyService::class)->resetPassword($companies['Gamma Fictive'], $this->admin);
        $companies['Delta Fictive']->user->forceFill(['deactivated_at' => now()])->save();
        EventCompany::query()->where('company_id', $companies['Epsilon Fictive']->id)->delete();
        EventCompany::query()->where('company_id', $companies['Alpha Fictive']->id)->update(['floor' => '2', 'table_label' => 'C03']);

        $data = $this->actingAs($this->admin, 'web')->getJson("/api/v1/company-imports/{$import->id}/credentials")->assertOk()->json('data');

        $this->assertSame(['Alpha Fictive', 'Epsilon Fictive'], array_column($data['sheets'], 'name'));
        $this->assertSame('C03', $data['sheets'][0]['event']['table_label']);
        $this->assertNull($data['sheets'][0]['notice']);
        $this->assertSame('Ne participe plus à l’événement', $data['sheets'][1]['notice']);
        $reasons = array_column($data['excluded'], 'reason', 'name');
        $this->assertSame('Mot de passe changé ou réinitialisé depuis l’import', $reasons['Beta Fictive']);
        $this->assertSame('Mot de passe changé ou réinitialisé depuis l’import', $reasons['Gamma Fictive']);
        $this->assertSame('Compte désactivé', $reasons['Delta Fictive']);

        $this->postJson("/api/v1/company-imports/{$import->id}/credentials-printed")->assertOk()->assertJsonPath('data.marked', 2)->assertJsonPath('data.skipped', 3);
        $this->assertNotNull($companies['Alpha Fictive']->refresh()->credentials_printed_at);
        $this->assertNull($companies['Beta Fictive']->refresh()->credentials_printed_at);
        $this->postJson("/api/v1/company-imports/{$import->id}/credentials-printed")->assertOk()->assertJsonPath('data.marked', 0);
    }

    public function test_evenement_supprime_apres_l_import_et_suppression_des_mots_de_passe(): void
    {
        $import = $this->import($this->workbook([2 => self::row('Alpha Fictive')]));
        $this->actingAs($this->admin, 'web')->deleteJson("/api/v1/events/{$this->event->id}")->assertNoContent();

        $this->getJson("/api/v1/company-imports/{$import->id}")->assertOk()->assertJsonPath('data.event.deleted', true)->assertJsonPath('data.event.name', 'JobDating '.$this->event->school_year);
        $sheet = $this->getJson("/api/v1/company-imports/{$import->id}/credentials")->assertOk()->json('data.sheets.0');
        $this->assertNull($sheet['event']);
        $this->assertSame('Événement supprimé', $sheet['notice']);

        $this->deleteJson("/api/v1/company-imports/{$import->id}/credentials")->assertNoContent();
        $this->getJson("/api/v1/company-imports/{$import->id}/credentials")->assertNotFound();
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::CompanyImportCredentialsDeleted->value)->count());
    }

    // ---------------------------------------------------------------- Clé de nom

    public function test_cle_de_nom_creation_renommage_et_noms_longs(): void
    {
        $prefix = str_repeat('a', 70);
        $first = app(CompanyService::class)->create(['name' => $prefix.' Un', 'username' => 'long-un'], null, $this->admin, ['event_id' => $this->event->id])['company'];
        $second = app(CompanyService::class)->create(['name' => $prefix.' Deux', 'username' => 'long-deux'], null, $this->admin, ['event_id' => $this->event->id])['company'];

        $this->assertSame(64, strlen((string) $first->name_key));
        $this->assertNotSame($first->name_key, $second->name_key);

        app(CompanyService::class)->update($first, ['name' => 'Renommée Fictive'], $this->admin);
        $this->assertSame(CompanyNameKey::of('renommee-fictive'), $first->refresh()->name_key);
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::CompanyUpdated->value)->whereJsonContains('new_values->name_key', $first->name_key)->count());

        $book = $this->workbook([2 => self::row('RENOMMEE fictive'), 3 => self::row($prefix.' Deux'), 4 => self::row($prefix.' Trois')]);
        $this->assertSame(['create' => 1, 'existing' => 2, 'duplicate' => 0, 'reject' => 0, 'unconfirmed' => 0], $this->preview($book->file())->json('data.counts'));
    }

    // ---------------------------------------------------------------- Droits

    /** @return iterable<string, array{string, string}> */
    public static function routes(): iterable
    {
        yield 'noms attendus' => ['GET', '/api/v1/company-imports/expected'];
        yield 'aperçu' => ['POST', '/api/v1/company-imports/preview'];
        yield 'confirmation' => ['POST', '/api/v1/company-imports'];
        yield 'historique' => ['GET', '/api/v1/company-imports'];
        yield 'rapport' => ['GET', '/api/v1/company-imports/1'];
        yield 'fiches' => ['GET', '/api/v1/company-imports/1/credentials'];
        yield 'imprimées' => ['POST', '/api/v1/company-imports/1/credentials-printed'];
        yield 'suppression' => ['DELETE', '/api/v1/company-imports/1/credentials'];
    }

    #[DataProvider('routes')]
    public function test_reserve_a_l_administrateur(string $method, string $uri): void
    {
        foreach ([Role::Cme, Role::Direction, Role::Entreprise, Role::Apprenant] as $role) {
            $this->app['auth']->forgetGuards();
            $this->actingAs(User::factory()->withRole($role)->create(), 'web')->json($method, $uri)->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->json($method, $uri)->assertUnauthorized();
    }

    public function test_noms_attendus(): void
    {
        $this->actingAs($this->admin, 'web')->getJson('/api/v1/company-imports/expected')->assertOk()
            ->assertExactJson(['data' => ['sheet' => 'Entreprises Invitées', 'name' => 'Entreprises', 'confirmation' => 'Confirmation par mail']]);
        $this->assertSame(0, DB::table('company_imports')->count());
    }
}
