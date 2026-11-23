<?php

namespace Tests\Feature\Learners;

use App\Enums\AuditAction;
use App\Enums\PasswordTokenType;
use App\Enums\Role;
use App\Jobs\ProcessLearnerImport;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\LearnerImport;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Competence;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\SchoolClass;
use App\Models\User;
use App\Notifications\GeneratedPasswordNotification;
use App\Services\Imports\LearnerImportRunner;
use App\Services\Imports\PasswordFileExporter;
use App\Services\Passwords\PasswordTokenService;
use App\Support\PrivateTemp;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Import des apprenants (ADM-04, RG-IMP-01 à 07, T-13, plan v2.1). Fichiers FICTIFS uniquement.
 */
class LearnerImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['ID', 'Prénom', 'Nom', 'Nom Complet', 'E-mail Personnel', 'E-mail Professionnel', 'Classe', 'Téléphone', 'Ville', 'Date de Naissance', 'CNIE', 'Statut'];

    /** Valeurs sentinelles des colonnes ignorées : ne doivent apparaître nulle part. */
    private const CITY = 'VilleSentinelleFictive';

    private const BIRTH = '01/01/1990 (36)';

    private const PERSONAL = 'perso.sentinelle@example.test';

    private User $admin;

    private Campus $campus;

    private Promotion $promotion;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
        Queue::fake();
        $this->admin = User::factory()->withRole(Role::Administrateur)->create();
        $this->campus = Campus::query()->create(['name' => 'Campus Fictif']);
        $this->promotion = Promotion::query()->create(['label' => 'Promotion Fictive']);
        $this->class = SchoolClass::query()->create(['name' => 'JAIWAR', 'filiere_id' => Filiere::query()->create(['code' => 'JAVA', 'label' => 'Java'])->id]);
        $this->withHeader('Origin', 'http://localhost');
    }

    /** @param list<string> $cells */
    private static function csvLine(array $cells): string
    {
        return implode(',', array_map(fn (string $c) => '"'.str_replace('"', '""', $c).'"', $cells));
    }

    /**
     * Ligne fictive au format de YouCode School Admin.
     *
     * @return list<string>
     */
    private static function row(string $cnie, string $first, string $email, string $class = 'J A I W A R', string $status = 'Actif', string $phone = '0600000000'): array
    {
        return ['#1', $first, 'Fictif', $first.' Fictif', self::PERSONAL, $email, $class, $phone, self::CITY, self::BIRTH, $cnie, $status];
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows, ?string $title = null, string $name = 'export.csv'): UploadedFile
    {
        $lines = array_merge($title !== null ? ['"'.$title.'"'] : [], [self::csvLine(self::HEADERS)], array_map(self::csvLine(...), $rows));

        return UploadedFile::fake()->createWithContent($name, implode("\n", $lines)."\n");
    }

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows, bool $formula = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(self::HEADERS));
        foreach ($rows as $i => $r) {
            $cells = array_map(fn (string $v) => new StringCell($v, null), $r);
            if ($formula && $i === 0) {
                $cells[1] = new FormulaCell('=1+1', null, null);
            }
            $writer->addRow(new Row($cells));
        }
        $writer->close();

        return UploadedFile::fake()->createWithContent('export.xlsx', (string) file_get_contents($path));
    }

    /**
     * @return array<string, mixed>
     */
    private function preview(UploadedFile $file): array
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->admin, 'web')->post('/api/v1/learner-imports/preview', [
            'file' => $file, 'campus_id' => $this->campus->id, 'promotion_id' => $this->promotion->id,
        ], ['Accept' => 'application/json'])->assertOk()->json('data');
    }

    private function confirm(UploadedFile $file, string $fingerprint, ?string $key = null): TestResponse
    {
        return $this->actingAs($this->admin, 'web')->post('/api/v1/learner-imports', [
            'file' => $file, 'campus_id' => $this->campus->id, 'promotion_id' => $this->promotion->id,
            'fingerprint' => $fingerprint, 'confirmation_key' => $key ?? (string) Str::uuid(),
        ], ['Accept' => 'application/json']);
    }

    /** Aperçu, confirmation et traitement (exécuté ici, la file étant simulée). */
    private function import(UploadedFile $file): LearnerImport
    {
        $fingerprint = $this->preview($file)['fingerprint'];
        $id = (int) $this->confirm($file, $fingerprint)->assertCreated()->json('data.id');
        app(LearnerImportRunner::class)->run($id);

        return LearnerImport::query()->findOrFail($id);
    }

    // ---------------------------------------------------------------- Droits

    /** @return iterable<string, array{Role}> */
    public static function others(): iterable
    {
        foreach ([Role::Cme, Role::Direction, Role::Entreprise, Role::Apprenant] as $role) {
            yield $role->value => [$role];
        }
    }

    #[DataProvider('others')]
    public function test_import_reserve_a_l_administrateur(Role $role): void
    {
        $this->actingAs(User::factory()->withRole($role)->create(), 'web');
        $this->post('/api/v1/learner-imports/preview', ['file' => $this->csv([self::row('ZZ100001', 'Amina', 'amina@example.test')])], ['Accept' => 'application/json'])->assertForbidden();
        $this->getJson('/api/v1/learner-imports')->assertForbidden();
        $this->getJson('/api/v1/learner-imports/1/passwords')->assertForbidden();
    }

    // ---------------------------------------------------------------- Lecture et aperçu

    public function test_apercu_csv_avec_motifs_de_rejet_et_colonnes_ignorees(): void
    {
        User::factory()->create(['email' => 'pris@example.test']);
        Learner::query()->forceCreate(['user_id' => User::factory()->withRole(Role::Apprenant)->create(['email' => 'connu@example.test'])->id, 'cnie' => 'ZZ100009', 'campus_id' => $this->campus->id, 'promotion_id' => $this->promotion->id, 'class_id' => $this->class->id]);
        $file = $this->csv([
            self::row('zz100001', 'Amina', 'amina@example.test'),                      // créer (CNIE en minuscules, classe espacée)
            self::row('', 'Sans', 'sans@example.test'),                               // CNIE manquante
            self::row('ZZ-1', 'Bad', 'bad@example.test'),                              // CNIE invalide
            self::row('ZZ100002', 'Double', 'd1@example.test'),                        // CNIE en double
            self::row('ZZ100002', 'Double', 'd2@example.test'),                        // CNIE en double
            self::row('ZZ100003', 'Mail', 'pas-un-mail'),                              // e-mail invalide
            self::row('ZZ100004', str_repeat('P', 101), 'long@example.test'),         // trop long
            self::row('ZZ100005', 'Autre', 'autre@example.test', 'CLASSE INCONNUE'),   // classe inconnue
            self::row('ZZ100006', 'Pris', 'pris@example.test'),                        // e-mail déjà utilisé
            self::row('ZZ100007', 'Tel', 'tel@example.test', phone: str_repeat('6', 31)), // téléphone trop long
            self::row('ZZ100009', 'Connu', 'connu@example.test'),                      // mettre à jour
        ], title: 'Export YouCode School Admin');

        $preview = $this->preview($file);
        $this->assertSame(['create' => 1, 'update' => 1, 'unchanged' => 0, 'reject' => 9], $preview['counts']);
        $reasons = array_column($preview['rejections'], 'reason');
        foreach (['CNIE manquante', 'CNIE invalide', 'CNIE en double', 'E-mail invalide', 'Prénom ou nom manquant ou trop long', 'Classe inconnue ou désactivée : vérifiez les référentiels', 'E-mail déjà utilisé', 'Téléphone trop long'] as $reason) {
            $this->assertContains($reason, $reasons);
        }
        $json = json_encode($preview) ?: '';
        foreach (['ZZ100002', 'connu@example.test', self::CITY, self::PERSONAL] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame(0, LearnerImport::query()->count());
    }

    public function test_en_tetes_manquants_et_format_refuses(): void
    {
        $this->actingAs($this->admin, 'web');
        $bad = UploadedFile::fake()->createWithContent('export.csv', "\"Prénom\",\"Nom\"\n\"A\",\"B\"\n");
        $this->post('/api/v1/learner-imports/preview', ['file' => $bad, 'campus_id' => $this->campus->id, 'promotion_id' => $this->promotion->id], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'En-têtes manquants : E-mail Professionnel, Classe, CNIE, Statut.');
        $this->post('/api/v1/learner-imports/preview', ['file' => UploadedFile::fake()->createWithContent('a.txt', 'x'), 'campus_id' => $this->campus->id, 'promotion_id' => $this->promotion->id], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->campus->forceFill(['active' => false])->save();
        $this->post('/api/v1/learner-imports/preview', ['file' => $this->csv([self::row('ZZ100001', 'Amina', 'amina@example.test')]), 'campus_id' => $this->campus->id, 'promotion_id' => $this->promotion->id], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('campus_id');
    }

    public function test_xlsx_et_point_virgule_formule_refusee(): void
    {
        $preview = $this->preview($this->xlsx([self::row('ZZ100001', 'Amina', 'amina@example.test'), self::row('ZZ100002', 'Brahim', 'brahim@example.test')], formula: true));
        $this->assertSame(1, $preview['counts']['create']);
        $this->assertSame('Valeur non admise', $preview['rejections'][0]['reason']);

        $semi = UploadedFile::fake()->createWithContent('export.csv', implode(';', self::HEADERS)."\n".implode(';', self::row('ZZ100001', 'Amina', 'amina@example.test'))."\n");
        $this->assertSame(1, $this->preview($semi)['counts']['create']);
        $this->assertSame([], File::directories(PrivateTemp::root()));
    }

    // ---------------------------------------------------------------- Confirmation

    public function test_confirmation_empreinte_cle_et_mise_en_file(): void
    {
        $file = $this->csv([self::row('ZZ100001', 'Amina', 'amina@example.test')]);
        $fingerprint = $this->preview($file)['fingerprint'];

        $this->confirm($file, str_repeat('0', 64))->assertStatus(409)->assertJsonPath('code', 'preview_changed');
        $key = (string) Str::uuid();
        $id = $this->confirm($file, $fingerprint, $key)->assertCreated()->json('data.id');
        $this->confirm($file, $fingerprint, $key)->assertOk()->assertJsonPath('data.id', $id);
        $this->confirm($file, str_repeat('1', 64), $key)->assertStatus(422)->assertJsonValidationErrors('confirmation_key');
        $this->assertSame(1, LearnerImport::query()->count());

        Queue::assertPushedOn('imports', ProcessLearnerImport::class, fn (ProcessLearnerImport $job) => $job->importId === $id && $job->connection === 'database-imports');
        $this->assertStringNotContainsString('amina', serialize(new ProcessLearnerImport($id)));
        $this->assertSame(LearnerImport::PENDING, LearnerImport::query()->findOrFail($id)->status);
    }

    public function test_empreinte_change_a_comptes_constants(): void
    {
        // A rejetée (e-mail pris), B à créer ; puis l'inverse : mêmes comptes, empreinte différente.
        $holder = User::factory()->create(['email' => 'a@example.test']);
        $file = $this->csv([self::row('ZZ100001', 'Aaa', 'a@example.test'), self::row('ZZ100002', 'Bbb', 'b@example.test')]);
        $first = $this->preview($file);
        $holder->forceFill(['email' => 'b@example.test'])->save();
        $second = $this->preview($file);

        $this->assertSame($first['counts'], $second['counts']);
        $this->assertNotSame($first['fingerprint'], $second['fingerprint']);
        $this->confirm($file, $first['fingerprint'])->assertStatus(409);
    }

    // ---------------------------------------------------------------- Traitement

    public function test_traitement_creation_actifs_et_inactif_mots_de_passe_et_colonnes_ignorees(): void
    {
        $import = $this->import($this->csv([
            self::row('ZZ100001', 'Amina', 'amina@example.test'),
            self::row('ZZ100002', 'Brahim', 'brahim@example.test', status: 'Inactif'),
        ]));

        $this->assertSame(LearnerImport::DONE, $import->status);
        $this->assertNull($import->getRawOriginal('payload'));
        $amina = User::query()->where('email', 'amina@example.test')->firstOrFail();
        $brahim = User::query()->where('email', 'brahim@example.test')->firstOrFail();
        $this->assertNull($amina->deactivated_at);
        $this->assertNotNull($brahim->deactivated_at);
        $this->assertNotSame('', (string) $brahim->password);

        $passwords = $import->passwords ?? [];
        $this->assertCount(1, $passwords);
        $this->assertSame('amina@example.test', $passwords[0]['email']);
        $this->assertSame('JAIWAR', $passwords[0]['class']);
        $this->assertTrue(Hash::check($passwords[0]['password'], (string) $amina->password));
        Notification::assertSentTo($amina, GeneratedPasswordNotification::class, fn (GeneratedPasswordNotification $n) => $n->reason === GeneratedPasswordNotification::REASON_CREATED);
        Notification::assertNotSentTo($brahim, GeneratedPasswordNotification::class);

        // Colonnes ignorées : nulle part en base (toutes tables texte du domaine).
        $dump = json_encode([DB::table('users')->get(), DB::table('learners')->get(), DB::table('audit_logs')->get(), DB::table('learner_imports')->get(), DB::table('jobs')->get()]) ?: '';
        foreach ([self::CITY, self::PERSONAL, '01/01/1990'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertStringNotContainsString($passwords[0]['password'], $dump);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::LearnerImportCompleted)->count());
    }

    public function test_second_import_identique_rien_a_faire(): void
    {
        $file = $this->csv([self::row('ZZ100001', 'Amina', 'amina@example.test')]);
        $this->import($file);
        Notification::fake();

        $preview = $this->preview($file);
        $this->assertSame(['create' => 0, 'update' => 0, 'unchanged' => 1, 'reject' => 0], $preview['counts']);
        $this->confirm($file, $preview['fingerprint'])->assertStatus(422);
        Notification::assertNothingSent();
        $this->assertSame(1, Learner::query()->count());
    }

    public function test_mise_a_jour_e_mail_statut_competences_et_mot_de_passe_conserves(): void
    {
        $this->import($this->csv([self::row('ZZ100001', 'Amina', 'amina@example.test')]));
        $learner = Learner::query()->where('cnie', 'ZZ100001')->firstOrFail();
        $hash = (string) $learner->user->password;
        $learner->competences()->attach(Competence::query()->create(['label' => 'SQL'])->id);
        app(PasswordTokenService::class)->issue($learner->user, PasswordTokenType::Reset);

        $import = $this->import($this->csv([self::row('ZZ100001', 'Amina', 'amina.nouveau@example.test', status: 'Abandon')]));
        $user = $learner->user->fresh();
        $this->assertSame(1, $import->updated_count);
        $this->assertSame('amina.nouveau@example.test', $user?->email);
        $this->assertNotNull($user?->deactivated_at);
        $this->assertSame($hash, (string) $user?->password);
        $this->assertSame(1, $learner->competences()->count());
        $this->assertSame(0, DB::table('password_tokens')->where('user_id', $learner->user_id)->count());
        $this->assertNull($import->passwords);

        $this->import($this->csv([self::row('ZZ100001', 'Amina', 'amina.nouveau@example.test')]));
        $this->assertNull($learner->user->fresh()?->deactivated_at);
    }

    public function test_conflit_entre_confirmation_et_traitement_rien_n_est_ecrit(): void
    {
        $file = $this->csv([self::row('ZZ100001', 'Amina', 'amina@example.test'), self::row('ZZ100002', 'Brahim', 'brahim@example.test')]);
        $id = (int) $this->confirm($file, $this->preview($file)['fingerprint'])->json('data.id');
        User::factory()->create(['email' => 'brahim@example.test']);

        app(LearnerImportRunner::class)->run($id);
        $import = LearnerImport::query()->findOrFail($id);
        $this->assertSame(LearnerImport::FAILED, $import->status);
        $this->assertSame(LearnerImportRunner::CONFLICT_ERROR, $import->error);
        $this->assertNull($import->getRawOriginal('payload'));
        $this->assertSame(0, Learner::query()->count());
        Notification::assertNothingSent();
    }

    public function test_etats_terminaux_proteges_double_traitement_et_failed(): void
    {
        $import = $this->import($this->csv([self::row('ZZ100001', 'Amina', 'amina@example.test')]));
        $logs = AuditLog::query()->count();

        // Relivraison après succès : ni nouveau traitement ni passage en échec.
        app(LearnerImportRunner::class)->run($import->id);
        (new ProcessLearnerImport($import->id))->failed(null);
        $this->artisan('imports:cleanup')->assertSuccessful();
        $fresh = $import->fresh();
        $this->assertSame(LearnerImport::DONE, $fresh?->status);
        $this->assertTrue((bool) $fresh?->hasPasswords());
        $this->assertSame($logs, AuditLog::query()->count());
        $this->assertSame(1, Learner::query()->count());
    }

    public function test_nettoyage_des_imports_abandonnes_et_dossiers_temporaires(): void
    {
        $file = $this->csv([self::row('ZZ100001', 'Amina', 'amina@example.test')]);
        $old = (int) $this->confirm($file, $this->preview($file)['fingerprint'])->json('data.id');
        $running = (int) $this->confirm($file, $this->preview($file)['fingerprint'])->json('data.id');
        LearnerImport::query()->whereKey($old)->update(['created_at' => now()->subMinutes(25)]);
        // Attendu 25 minutes, démarré il y a 2 minutes : son traitement légitime n'est pas interrompu.
        LearnerImport::query()->whereKey($running)->update(['status' => LearnerImport::PROCESSING, 'created_at' => now()->subMinutes(25), 'started_at' => now()->subMinutes(2)]);
        $stale = PrivateTemp::root().'/abandonne';
        File::ensureDirectoryExists($stale);
        touch($stale, time() - 56 * 60);

        $this->artisan('imports:cleanup')->assertSuccessful();
        $this->assertSame(LearnerImport::FAILED, LearnerImport::query()->findOrFail($old)->status);
        $this->assertNull(LearnerImport::query()->findOrFail($old)->getRawOriginal('payload'));
        $this->assertSame(LearnerImport::PROCESSING, LearnerImport::query()->findOrFail($running)->status);
        $this->assertDirectoryDoesNotExist($stale);
    }

    // ---------------------------------------------------------------- Revue du code (constats)

    /** Constat 1 : doublon de CNIE ou d'e-mail rejeté même si l'autre ligne a déjà une autre erreur. */
    public function test_doublons_rejetes_meme_avec_une_autre_erreur(): void
    {
        $preview = $this->preview($this->csv([
            self::row('ZZ100001', 'Amina', 'amina@example.test'),
            self::row('ZZ100001', 'Amina', 'pas-un-mail'),
            self::row('ZZ100003', 'Chama', 'chama@example.test'),
            self::row('ZZ-INVALIDE', 'Autre', 'chama@example.test'),
        ]));

        $this->assertSame(['create' => 0, 'update' => 0, 'unchanged' => 0, 'reject' => 4], $preview['counts']);
        $this->assertSame([2 => 'CNIE en double', 4 => 'E-mail en double'], [
            2 => $preview['rejections'][0]['reason'],
            4 => $preview['rejections'][2]['reason'],
        ]);
    }

    /** Constat 2 : plus de 50 000 chaînes partagées refusé, même sans compteur déclaré. */
    public function test_xlsx_trop_de_chaines_partagees_sans_compteur_refuse(): void
    {
        $file = $this->xlsx([self::row('ZZ100001', 'Amina', 'amina@example.test')]);
        $zip = new \ZipArchive;
        $zip->open((string) $file->getRealPath());
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.str_repeat('<si><t>x</t></si>', 50001).'</sst>');
        $zip->close();

        $this->actingAs($this->admin, 'web')->post('/api/v1/learner-imports/preview', [
            'file' => $file, 'campus_id' => $this->campus->id, 'promotion_id' => $this->promotion->id,
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('errors.file.0', 'Le fichier Excel contient trop de textes différents.');
    }

    /** Contre-revue N3 : chaînes partagées mal formées → refus du fichier (422), jamais une erreur 500. */
    public function test_xlsx_chaines_partagees_mal_formees_refuse(): void
    {
        $file = $this->xlsx([self::row('ZZ100001', 'Amina', 'amina@example.test')]);
        $zip = new \ZipArchive;
        $zip->open((string) $file->getRealPath());
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>x</si></sst>');
        $zip->close();

        $this->actingAs($this->admin, 'web')->post('/api/v1/learner-imports/preview', [
            'file' => $file, 'campus_id' => $this->campus->id, 'promotion_id' => $this->promotion->id,
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('errors.file.0', 'Fichier Excel illisible.');
        $this->assertFalse(libxml_use_internal_errors());
    }

    /** Constats 5 et 9 : point-virgule après une ligne de titre ; numéros de ligne réels malgré les lignes vides. */
    public function test_point_virgule_avec_titre_et_numeros_apres_lignes_vides(): void
    {
        $content = "Export YouCode School Admin\n".implode(';', self::HEADERS)."\n\n".implode(';', self::row('', 'Sans', 'sans@example.test'))."\n".implode(';', self::row('ZZ100001', 'Amina', 'amina@example.test'))."\n";
        $preview = $this->preview(UploadedFile::fake()->createWithContent('export.csv', $content));

        $this->assertSame(1, $preview['counts']['create']);
        $this->assertSame([['line' => 4, 'reason' => 'CNIE manquante']], $preview['rejections']);
    }

    /** Constat 6 : un import pris en charge entre la sélection et l'annulation n'est pas annulé. */
    public function test_nettoyage_ne_coupe_pas_un_import_qui_vient_de_demarrer(): void
    {
        $file = $this->csv([self::row('ZZ100001', 'Amina', 'amina@example.test')]);
        $id = (int) $this->confirm($file, $this->preview($file)['fingerprint'])->json('data.id');
        LearnerImport::query()->whereKey($id)->update(['created_at' => now()->subMinutes(25)]);

        // Le worker démarre l'import juste après la sélection de la purge.
        $done = false;
        DB::listen(function ($query) use (&$done, $id): void {
            if (! $done && str_starts_with($query->sql, 'select `id` from `learner_imports`')) {
                $done = true;
                DB::table('learner_imports')->where('id', $id)->update(['status' => LearnerImport::PROCESSING, 'started_at' => now()]);
            }
        });

        $this->artisan('imports:cleanup')->assertSuccessful();
        $this->assertTrue($done);
        $this->assertSame(LearnerImport::PROCESSING, LearnerImport::query()->findOrFail($id)->status);
        $this->assertNotNull(LearnerImport::query()->findOrFail($id)->getRawOriginal('payload'));
    }

    /** Constat 7 : une ligne « inchangée » devenue invalide avant le traitement fait échouer l'import. */
    public function test_ligne_inchangee_revalidee_au_traitement(): void
    {
        $this->import($this->csv([self::row('ZZ100001', 'Amina', 'amina@example.test')]));
        $file = $this->csv([self::row('ZZ100001', 'Amina', 'amina@example.test'), self::row('ZZ100002', 'Brahim', 'brahim@example.test')]);
        $preview = $this->preview($file);
        $this->assertSame(['create' => 1, 'update' => 0, 'unchanged' => 1, 'reject' => 0], $preview['counts']);
        $id = (int) $this->confirm($file, $preview['fingerprint'])->json('data.id');
        User::query()->where('email', 'amina@example.test')->firstOrFail()->delete();

        app(LearnerImportRunner::class)->run($id);
        $this->assertSame(LearnerImport::FAILED, LearnerImport::query()->findOrFail($id)->status);
        $this->assertFalse(User::withTrashed()->where('email', 'brahim@example.test')->exists());
    }

    // ---------------------------------------------------------------- Classe lue sur chaque ligne (v2.2)

    /** Fichier à plusieurs classes : chaque apprenant reçoit la classe de sa ligne ; comptes par classe. */
    public function test_classe_lue_sur_chaque_ligne_plusieurs_classes(): void
    {
        $data = Filiere::query()->create(['code' => 'DATA', 'label' => 'Data']);
        $leek = SchoolClass::query()->create(['name' => 'AI LEEK', 'filiere_id' => $data->id]);
        SchoolClass::query()->create(['name' => 'ANCIENNE', 'filiere_id' => $data->id, 'active' => false]);
        $file = $this->csv([
            self::row('ZZ100001', 'Amina', 'amina@example.test'),                          // JAIWAR (espacée)
            self::row('ZZ100002', 'Brahim', 'brahim@example.test', 'ai leek'),               // AI LEEK (casse)
            self::row('ZZ100003', 'Chama', 'chama@example.test', 'AILEEK'),                  // AI LEEK (espaces)
            self::row('ZZ100004', 'Driss', 'driss@example.test', 'INCONNUE'),                // inconnue
            self::row('ZZ100005', 'Ely', 'ely@example.test', 'ANCIENNE'),                    // désactivée
            self::row('ZZ100006', 'Fadwa', 'fadwa@example.test', ''),                        // vide
        ], title: 'YouCode School Admin | Étudiants');

        $preview = $this->preview($file);
        $this->assertSame(['create' => 3, 'update' => 0, 'unchanged' => 0, 'reject' => 3], $preview['counts']);
        $this->assertSame([
            ['class' => 'AI LEEK', 'create' => 2, 'update' => 0, 'unchanged' => 0],
            ['class' => 'JAIWAR', 'create' => 1, 'update' => 0, 'unchanged' => 0],
        ], $preview['classes']);
        $this->assertSame([
            ['line' => 6, 'reason' => 'Classe inconnue ou désactivée : vérifiez les référentiels'],
            ['line' => 7, 'reason' => 'Classe inconnue ou désactivée : vérifiez les référentiels'],
            ['line' => 8, 'reason' => 'Classe manquante'],
        ], $preview['rejections']);

        $id = (int) $this->confirm($file, $preview['fingerprint'])->assertCreated()->assertJsonPath('data.classes', $preview['classes'])->json('data.id');
        app(LearnerImportRunner::class)->run($id);
        $import = LearnerImport::query()->findOrFail($id);
        $this->assertSame(LearnerImport::DONE, $import->status);
        $this->assertSame($this->class->id, Learner::query()->where('cnie', 'ZZ100001')->value('class_id'));
        $this->assertSame($leek->id, Learner::query()->where('cnie', 'ZZ100002')->value('class_id'));
        $this->assertSame($leek->id, Learner::query()->where('cnie', 'ZZ100003')->value('class_id'));
        $this->assertSame(3, Learner::query()->count());
        $classes = array_column($import->passwords ?? [], 'class', 'email');
        $this->assertSame(['amina@example.test' => 'JAIWAR', 'brahim@example.test' => 'AI LEEK', 'chama@example.test' => 'AI LEEK'], $classes);
        $this->getJson('/api/v1/learner-imports/'.$id)->assertOk()->assertJsonPath('data.classes', $preview['classes']);
    }

    /** Deux classes actives de même nom une fois casse et espaces ignorés : ligne rejetée (ambiguë). */
    public function test_classe_ambigue_rejetee(): void
    {
        SchoolClass::query()->create(['name' => 'JAI WAR', 'filiere_id' => $this->class->filiere_id]);

        $preview = $this->preview($this->csv([self::row('ZZ100001', 'Amina', 'amina@example.test')]));
        $this->assertSame([['line' => 2, 'reason' => 'Classe ambiguë dans les référentiels']], $preview['rejections']);
    }

    /** Apprenant connu : la classe du fichier remplace sa classe actuelle (mise à jour). */
    public function test_apprenant_connu_change_de_classe_selon_le_fichier(): void
    {
        $leek = SchoolClass::query()->create(['name' => 'AI LEEK', 'filiere_id' => $this->class->filiere_id]);
        $this->import($this->csv([self::row('ZZ100001', 'Amina', 'amina@example.test')]));

        $import = $this->import($this->csv([self::row('ZZ100001', 'Amina', 'amina@example.test', 'AI LEEK')]));
        $this->assertSame(1, $import->updated_count);
        $this->assertSame($leek->id, Learner::query()->where('cnie', 'ZZ100001')->value('class_id'));
    }

    /** Classe désactivée entre l'aperçu et la confirmation : 409 ; entre la confirmation et le traitement : échec, rien d'écrit. */
    public function test_classe_desactivee_apres_l_apercu(): void
    {
        $file = $this->csv([self::row('ZZ100001', 'Amina', 'amina@example.test')]);
        $fingerprint = $this->preview($file)['fingerprint'];
        $this->class->forceFill(['active' => false])->save();
        $this->confirm($file, $fingerprint)->assertStatus(409);

        $this->class->forceFill(['active' => true])->save();
        $id = (int) $this->confirm($file, $this->preview($file)['fingerprint'])->assertCreated()->json('data.id');
        $this->class->forceFill(['active' => false])->save();
        app(LearnerImportRunner::class)->run($id);

        $import = LearnerImport::query()->findOrFail($id);
        $this->assertSame(LearnerImport::FAILED, $import->status);
        $this->assertSame(LearnerImportRunner::CONFLICT_ERROR, $import->error);
        $this->assertSame(0, Learner::query()->count());
    }

    // ---------------------------------------------------------------- Fichier des mots de passe

    public function test_fichier_des_mots_de_passe_plusieurs_telechargements_puis_suppression(): void
    {
        // Une valeur commençant par « = » est refusée à la lecture (formule) ; « @ » est neutralisé à l'écriture.
        $this->assertSame("'=1+1", PasswordFileExporter::neutralize('=1+1'));
        $import = $this->import($this->csv([self::row('ZZ100001', '@Amina', 'amina@example.test')]));
        $url = "/api/v1/learner-imports/{$import->id}/passwords";

        foreach ([1, 2] as $_) {
            $response = $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $path = tempnam(sys_get_temp_dir(), 'pw').'.xlsx';
            file_put_contents($path, $response->getContent());
            $reader = new XlsxReader;
            $reader->open($path);
            $values = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $values[] = $row->toArray();
                }
            }
            $reader->close();
            $this->assertSame(PasswordFileExporter::HEADERS, $values[0]);
            $this->assertSame("'@Amina", $values[1][0]);
            $this->assertSame(($import->passwords ?? [])[0]['password'], $values[1][4]);
        }
        $this->assertSame(2, AuditLog::query()->where('action', AuditAction::LearnerImportPasswordsDownloaded)->count());
        $this->assertSame([], File::directories(PrivateTemp::root()));

        $this->deleteJson($url)->assertNoContent();
        $this->get($url)->assertNotFound();
        $this->assertNull($import->fresh()?->getRawOriginal('passwords'));
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::LearnerImportPasswordsDeleted)->count());
        $this->getJson("/api/v1/learner-imports/{$import->id}")->assertOk()->assertJsonMissingPath('data.passwords')->assertJsonPath('data.has_passwords', false);
    }
}
