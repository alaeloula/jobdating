<?php

namespace Tests\Feature\Companies;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Referentials\Competence;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\Sector;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Companies\CompanyService;
use App\Services\Companies\UsernameSuggester;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Entreprises (ADM-02, RG-ENTR-01 à 08, T-11, plan v2).
 */
class CompanyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Notification::fake();
        $this->admin = User::factory()->withRole(Role::Administrateur)->create();
        $this->withHeader('Origin', 'http://localhost');
    }

    /** PNG minimal valide pour getimagesize / finfo (dimensions dans l'en-tête IHDR). */
    private static function png(int $width = 120, int $height = 60): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress("\x00\x00\x00\x00"))
            .$chunk('IEND', '');
    }

    private function logo(int $width = 120, int $height = 60, string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::png($width, $height));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function create(array $extra = []): array
    {
        $response = $this->actingAs($this->admin, 'web')
            ->post('/api/v1/companies', array_merge(['name' => 'Société Générale', 'username' => 'societe-generale'], $extra), ['Accept' => 'application/json'])
            ->assertCreated();

        return $response->json();
    }

    // ---------------------------------------------------------------- Droits

    /**
     * @return iterable<string, array{Role}>
     */
    public static function others(): iterable
    {
        yield 'direction' => [Role::Direction];
        yield 'entreprise' => [Role::Entreprise];
        yield 'apprenant' => [Role::Apprenant];
    }

    #[DataProvider('others')]
    public function test_gestion_et_lecture_refusees_aux_autres_roles(Role $role): void
    {
        $company = $this->makeCompany();
        $this->actingAs(User::factory()->withRole($role)->create(), 'web');

        $this->getJson('/api/v1/companies')->assertForbidden();
        $this->getJson("/api/v1/companies/{$company->id}")->assertForbidden();
        $this->postJson('/api/v1/companies', ['name' => 'X', 'username' => 'xxx'])->assertForbidden();
        $this->patchJson("/api/v1/companies/{$company->id}", ['name' => 'Y'])->assertForbidden();
        $this->putJson("/api/v1/companies/{$company->id}/profiles", ['filiere_ids' => [], 'competence_ids' => []])->assertForbidden();
        $this->postJson("/api/v1/companies/{$company->id}/reset-password")->assertForbidden();
        $this->postJson("/api/v1/companies/{$company->id}/deactivate")->assertForbidden();
        $this->postJson("/api/v1/companies/{$company->id}/credentials-printed", ['version' => 1])->assertForbidden();
    }

    public function test_la_cme_lit_et_modifie_seulement_les_profils(): void
    {
        $company = $this->makeCompany();
        $this->actingAs(User::factory()->withRole(Role::Cme)->create(), 'web');

        $list = $this->getJson('/api/v1/companies')->assertOk()->json('data.0');
        $this->assertSame($company->user->username, $list['username']);
        $this->assertArrayNotHasKey('credentials', $list);
        $this->getJson("/api/v1/companies/{$company->id}")->assertOk();
        $this->putJson("/api/v1/companies/{$company->id}/profiles", ['filiere_ids' => [], 'competence_ids' => []])->assertOk();

        $this->postJson('/api/v1/companies', ['name' => 'X', 'username' => 'xxx'])->assertForbidden();
        $this->patchJson("/api/v1/companies/{$company->id}", ['name' => 'Y'])->assertForbidden();
        $this->postJson("/api/v1/companies/{$company->id}/reset-password")->assertForbidden();
        $this->postJson("/api/v1/companies/{$company->id}/credentials-printed", ['version' => 1])->assertForbidden();
        $this->postJson("/api/v1/companies/{$company->id}/deactivate")->assertForbidden();
        $this->getJson('/api/v1/companies/username-suggestion?name=X')->assertForbidden();
    }

    public function test_sans_session(): void
    {
        $this->getJson('/api/v1/companies')->assertUnauthorized();
        $this->putJson('/api/v1/me/company', ['description' => 'x'])->assertUnauthorized();
    }

    // ---------------------------------------------------------------- Création et identifiants

    public function test_creation_identifiants_une_fois_connexion_sans_changement_ni_e_mail(): void
    {
        $sector = Sector::query()->create(['label' => 'Banque', 'active' => true]);
        $json = $this->create(['sector_id' => $sector->id, 'website' => 'https://exemple.test', 'contact_name' => 'Contact', 'contact_email' => 'contact@exemple.test']);

        $this->assertSame('societe-generale', $json['credentials']['username']);
        $this->assertSame(1, $json['credentials']['version']);
        $password = $json['credentials']['password'];
        $this->assertSame(16, strlen($password));
        $this->assertFalse($json['data']['credentials_printed']);

        $user = User::query()->where('username', 'societe-generale')->firstOrFail();
        $this->assertNotNull($user->activated_at);
        $this->assertNull($user->email);
        $this->assertSame(Role::Entreprise, $user->currentRole());
        $this->assertSame('Société Générale', $user->first_name);

        // Critère ADM-02 : connexion avec la fiche, sans changement imposé.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/login', ['identifier' => 'societe-generale', 'password' => $password])
            ->assertOk()->assertJsonPath('data.home', '/entreprise');

        Notification::assertNothingSent();
        $this->assertStringNotContainsString($password, json_encode(AuditLog::query()->get()->toArray(), JSON_THROW_ON_ERROR));
        $this->assertSame('Entreprise : Société Générale', AuditLog::query()->where('action', AuditAction::CompanyCreated->value)->value('subject_label'));
    }

    public function test_la_reponse_de_creation_n_est_jamais_mise_en_cache(): void
    {
        $this->actingAs($this->admin, 'web')
            ->post('/api/v1/companies', ['name' => 'Acme', 'username' => 'acme'], ['Accept' => 'application/json'])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_deux_entreprises_de_meme_nom_noms_d_utilisateur_differents(): void
    {
        $this->actingAs($this->admin, 'web');
        $first = $this->getJson('/api/v1/companies/username-suggestion?name=Acme Maroc')->json('data.username');
        $this->postJson('/api/v1/companies', ['name' => 'Acme Maroc', 'username' => $first])->assertCreated();
        $second = $this->getJson('/api/v1/companies/username-suggestion?name=Acme Maroc')->json('data.username');
        $this->postJson('/api/v1/companies', ['name' => 'Acme Maroc', 'username' => $second])->assertCreated();

        $this->assertSame(['acme-maroc', 'acme-maroc-2'], [$first, $second]);
    }

    public function test_propositions_toujours_valides(): void
    {
        $this->assertSame('hp-entreprise', UsernameSuggester::suggest('HP'));
        $this->assertSame('x-entreprise', UsernameSuggester::suggest('X'));
        $this->assertSame('entreprise', UsernameSuggester::suggest('!!!'));
        $this->assertSame('ecole-orange-telecom', UsernameSuggester::suggest('École Orange & Télécom'));

        $long = UsernameSuggester::suggest(str_repeat('Entreprise Tres Longue ', 7));
        $this->assertTrue(UsernameSuggester::valid($long));
        $this->assertLessThanOrEqual(50, strlen($long));

        // Doublons, y compris compte supprimé, et suffixes à plusieurs chiffres.
        $base = substr(UsernameSuggester::base(str_repeat('a', 60)), 0, 50);
        User::factory()->create(['username' => $base])->delete();
        for ($n = 2; $n <= 10; $n++) {
            User::factory()->create(['username' => substr($base, 0, 50 - strlen("-{$n}"))."-{$n}"]);
        }
        $next = UsernameSuggester::suggest(str_repeat('a', 60));
        $this->assertSame(substr($base, 0, 47).'-11', $next);
        $this->assertTrue(UsernameSuggester::valid($next));
    }

    public function test_nom_d_utilisateur_invalide_ou_deja_pris(): void
    {
        User::factory()->create(['username' => 'pris-supprime'])->delete();
        $this->actingAs($this->admin, 'web');

        foreach (['AB', 'Majuscules', 'avec espace', 'fin-', str_repeat('a', 51)] as $bad) {
            $this->postJson('/api/v1/companies', ['name' => 'X', 'username' => $bad])->assertStatus(422)->assertJsonValidationErrors('username');
        }
        $this->postJson('/api/v1/companies', ['name' => 'X', 'username' => 'pris-supprime'])->assertStatus(422)->assertJsonValidationErrors('username');
        $this->postJson('/api/v1/companies', ['username' => 'sans-nom'])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    /** Revue Codex T-11, constat 3 : profils saisis après la création, jamais ignorés en silence. */
    public function test_profils_refuses_a_la_creation(): void
    {
        $java = Filiere::query()->create(['code' => 'JAVA', 'label' => 'Java', 'active' => true]);
        $this->actingAs($this->admin, 'web');

        $this->postJson('/api/v1/companies', ['name' => 'Acme', 'username' => 'acme', 'filiere_ids' => [$java->id]])
            ->assertStatus(422)->assertJsonValidationErrors('filiere_ids');
        $this->postJson('/api/v1/companies', ['name' => 'Acme', 'username' => 'acme', 'competence_ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors('competence_ids');
        $this->assertSame(0, Company::query()->count());
    }

    public function test_nom_de_150_caracteres(): void
    {
        $name = str_repeat('N', 150);
        $this->create(['name' => $name]);

        $this->assertSame(str_repeat('N', 100), User::query()->where('username', 'societe-generale')->value('first_name'));
        $this->assertSame($name, Company::query()->value('name'));
    }

    public function test_site_web_et_logo_invalides_refuses(): void
    {
        $this->actingAs($this->admin, 'web');
        $post = fn (array $data) => $this->post('/api/v1/companies', ['name' => 'X', 'username' => 'xxx'] + $data, ['Accept' => 'application/json']);

        $post(['website' => 'pas une adresse'])->assertStatus(422)->assertJsonValidationErrors('website');
        $post(['website' => 'ftp://exemple.test'])->assertStatus(422)->assertJsonValidationErrors('website');
        $post(['logo' => UploadedFile::fake()->createWithContent('logo.png', 'pas une image')])->assertStatus(422)->assertJsonValidationErrors('logo');
        $post(['logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')])->assertStatus(422)->assertJsonValidationErrors('logo');
        $post(['logo' => $this->logo(3000, 100)])->assertStatus(422)->assertJsonValidationErrors('logo');
        $post(['logo' => UploadedFile::fake()->createWithContent('logo.png', self::png().str_repeat("\0", 1100 * 1024))])->assertStatus(422)->assertJsonValidationErrors('logo');

        $this->assertSame(0, Company::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_logo_valide_stocke_en_prive_et_servi(): void
    {
        $json = $this->create(['logo' => $this->logo()]);
        $company = Company::query()->findOrFail($json['data']['id']);

        $this->assertTrue($json['data']['has_logo']);
        Storage::disk('local')->assertExists((string) $company->logo_path);
        $this->assertStringStartsWith('logos/', (string) $company->logo_path);
        $this->get("/api/v1/companies/{$company->id}/logo")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_echec_apres_stockage_du_logo_aucun_fichier_ni_ecriture_partielle(): void
    {
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            /**
             * @param  array<string, mixed>  $old
             * @param  array<string, mixed>  $new
             */
            public function record(AuditAction $action, ?Model $subject = null, array $old = [], array $new = [], ?User $actor = null, bool $visitor = false): AuditLog
            {
                if ($action === AuditAction::CompanyCreated) {
                    throw new RuntimeException('journal indisponible');
                }

                return parent::record($action, $subject, $old, $new, $actor, $visitor);
            }
        });

        $this->actingAs($this->admin, 'web')
            ->post('/api/v1/companies', ['name' => 'Acme', 'username' => 'acme', 'logo' => $this->logo()], ['Accept' => 'application/json'])
            ->assertStatus(500);

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Company::query()->count());
        $this->assertFalse(User::withTrashed()->where('username', 'acme')->exists());
    }

    public function test_remplacement_du_logo_supprime_l_ancien_apres_validation(): void
    {
        $json = $this->create(['logo' => $this->logo()]);
        $id = $json['data']['id'];
        $first = (string) Company::query()->findOrFail($id)->logo_path;

        $this->post("/api/v1/companies/{$id}/logo", ['logo' => $this->logo(200, 100)], ['Accept' => 'application/json'])->assertOk();
        $second = (string) Company::query()->findOrFail($id)->logo_path;
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $this->post("/api/v1/companies/{$id}/logo", ['logo' => $this->logo(150, 100)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing($second);
        $this->assertCount(1, Storage::disk('local')->allFiles());

        $this->deleteJson("/api/v1/companies/{$id}/logo")->assertOk()->assertJsonPath('data.has_logo', false);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /** Revue Codex T-11, constat 1 : fichiers liés à la validation FINALE d'une transaction englobante. */
    public function test_logo_et_transaction_englobante_annulee(): void
    {
        $service = app(CompanyService::class);
        $id = $this->create(['logo' => $this->logo()])['data']['id'];
        $company = Company::query()->findOrFail($id);
        $first = (string) $company->logo_path;
        $rollback = fn (callable $operation) => rescue(fn () => DB::transaction(function () use ($operation): void {
            $operation();
            throw new RuntimeException('annulation par l’appelant');
        }), report: false);

        // Remplacement annulé par l'appelant : l'ancien fichier reste, le nouveau disparaît.
        $rollback(fn () => $service->replaceLogo($company, $this->logo(200, 100), $this->admin));
        $this->assertSame($first, Company::query()->findOrFail($id)->logo_path);
        Storage::disk('local')->assertExists($first);
        $this->assertCount(1, Storage::disk('local')->allFiles());

        // Suppression annulée par l'appelant : le fichier reste.
        $rollback(fn () => $service->removeLogo($company, $this->admin));
        Storage::disk('local')->assertExists($first);

        // Création annulée par l'appelant : aucun fichier orphelin.
        $rollback(fn () => $service->create(['name' => 'Annulée', 'username' => 'annulee'], $this->logo(), $this->admin));
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertFalse(User::withTrashed()->where('username', 'annulee')->exists());

        // Remplacement validé dans une transaction englobante : l'ancien disparaît après la validation finale.
        DB::transaction(fn () => $service->replaceLogo($company, $this->logo(200, 100), $this->admin));
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists((string) Company::query()->findOrFail($id)->logo_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_echec_interne_puis_annulation_englobante_une_seule_suppression(): void
    {
        $id = $this->create(['logo' => $this->logo()])['data']['id'];
        $first = (string) Company::query()->findOrFail($id)->logo_path;
        $captured = new \ArrayObject;
        $this->app->instance(AuditLogger::class, new class($captured) extends AuditLogger
        {
            /** @param \ArrayObject<int, string> $captured */
            public function __construct(private readonly \ArrayObject $captured) {}

            /**
             * @param  array<string, mixed>  $old
             * @param  array<string, mixed>  $new
             */
            public function record(AuditAction $action, ?Model $subject = null, array $old = [], array $new = [], ?User $actor = null, bool $visitor = false): AuditLog
            {
                if ($action === AuditAction::CompanyUpdated && $subject instanceof Company) {
                    $this->captured->append((string) $subject->logo_path);
                    throw new RuntimeException('journal indisponible');
                }

                return parent::record($action, $subject, $old, $new, $actor, $visitor);
            }
        });
        $service = app(CompanyService::class);

        // Échec interne (le catch supprime le nouveau fichier), puis annulation par l'appelant : le
        // rappel ne doit PAS supprimer une seconde fois. Un fichier remis au même chemin le prouve.
        rescue(fn () => DB::transaction(function () use ($service, $id, $captured): void {
            rescue(fn () => $service->replaceLogo(Company::query()->findOrFail($id), $this->logo(200, 100), $this->admin), report: false);
            Storage::disk('local')->put($captured[0], 'témoin');
            throw new RuntimeException('annulation par l’appelant');
        }), report: false);

        $this->assertNotSame($first, $captured[0]);
        Storage::disk('local')->assertExists($captured[0]);
        Storage::disk('local')->assertExists($first);
        $this->assertSame($first, Company::query()->findOrFail($id)->logo_path);
    }

    // ---------------------------------------------------------------- Fiche et réinitialisation

    public function test_reinitialisation_ferme_toutes_les_sessions_sans_e_mail(): void
    {
        $json = $this->create();
        $id = $json['data']['id'];
        $user = User::query()->where('username', 'societe-generale')->firstOrFail();
        foreach (['session-1', 'session-2'] as $sid) {
            DB::table('sessions')->insert(['id' => $sid, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        }
        $this->postJson("/api/v1/companies/{$id}/credentials-printed", ['version' => 1])->assertOk();

        $reset = $this->postJson("/api/v1/companies/{$id}/reset-password")->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(2, $reset->json('credentials.version'));
        $this->assertFalse($reset->json('data.credentials_printed'));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertFalse(Hash::check($json['credentials']['password'], (string) $user->fresh()?->password));
        $this->assertTrue(Hash::check($reset->json('credentials.password'), (string) $user->fresh()->password));
        Notification::assertNothingSent();
        $this->assertSame($this->admin->id, AuditLog::query()->where('action', AuditAction::PasswordReset->value)->value('user_id'));

        // Critère ADM-02 : après réinitialisation, l'ancien mot de passe est refusé.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/login', ['identifier' => 'societe-generale', 'password' => $json['credentials']['password']])->assertStatus(422);
    }

    public function test_confirmation_d_impression_liee_a_la_version_courante(): void
    {
        $json = $this->create();
        $id = $json['data']['id'];

        $this->postJson("/api/v1/companies/{$id}/reset-password")->assertOk();

        // Ancienne fiche (version 1) confirmée après la réinitialisation : refusée.
        $this->postJson("/api/v1/companies/{$id}/credentials-printed", ['version' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'stale_credentials');
        $this->assertNull(Company::query()->findOrFail($id)->credentials_printed_at);

        $this->postJson("/api/v1/companies/{$id}/credentials-printed", ['version' => 2])->assertOk()->assertJsonPath('data.credentials_printed', true);
        $this->postJson("/api/v1/companies/{$id}/credentials-printed", ['version' => 2])->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::CompanyCredentialsPrinted->value)->count());

        $this->assertSame([], array_column($this->getJson('/api/v1/companies?status=non_imprimee')->json('data'), 'id'));
    }

    // ---------------------------------------------------------------- Modification, secteur, désactivation

    public function test_secteur_inactif_conserve_mais_jamais_nouvellement_choisi(): void
    {
        $sector = Sector::query()->create(['label' => 'Industrie', 'active' => true]);
        $other = Sector::query()->create(['label' => 'Retraite', 'active' => false]);
        $id = $this->create(['sector_id' => $sector->id])['data']['id'];
        $sector->forceFill(['active' => false])->save();

        $this->patchJson("/api/v1/companies/{$id}", ['sector_id' => $sector->id, 'description' => 'Nouvelle'])->assertOk();
        $this->patchJson("/api/v1/companies/{$id}", ['sector_id' => $other->id])->assertStatus(422)->assertJsonValidationErrors('sector_id');
        $this->post('/api/v1/companies', ['name' => 'B', 'username' => 'bbb', 'sector_id' => $other->id], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('sector_id');

        $this->assertSame($sector->id, Company::query()->findOrFail($id)->sector_id);
    }

    public function test_modification_journalisee_nom_d_utilisateur_inchange(): void
    {
        $id = $this->create()['data']['id'];

        $this->patchJson("/api/v1/companies/{$id}", ['name' => 'SG Maroc', 'username' => 'autre'])->assertOk()->assertJsonPath('data.username', 'societe-generale');

        $log = AuditLog::query()->where('action', AuditAction::CompanyUpdated->value)->latest('id')->firstOrFail();
        $this->assertEquals(['nom' => 'Société Générale'], $log->old_values);
        $this->assertEquals(['nom' => 'SG Maroc'], $log->new_values);
        $this->assertSame('SG Maroc', User::query()->where('username', 'societe-generale')->value('first_name'));
    }

    public function test_desactivation_coupe_les_connexions(): void
    {
        $json = $this->create();
        $id = $json['data']['id'];
        $user = User::query()->where('username', 'societe-generale')->firstOrFail();
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->postJson("/api/v1/companies/{$id}/deactivate")->assertOk()->assertJsonPath('data.status', 'desactive');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->postJson("/api/v1/companies/{$id}/reset-password")->assertStatus(422)->assertJsonPath('code', 'not_resettable');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/login', ['identifier' => 'societe-generale', 'password' => $json['credentials']['password']])
            ->assertStatus(403)->assertJsonPath('code', 'account_deactivated');

        $this->actingAs($this->admin, 'web')->postJson("/api/v1/companies/{$id}/reactivate")->assertOk()->assertJsonPath('data.status', 'actif');
    }

    // ---------------------------------------------------------------- Profils recherchés

    public function test_profils_recherches_valeurs_inactives(): void
    {
        $java = Filiere::query()->create(['code' => 'JAVA', 'label' => 'Java', 'active' => true]);
        $js = Filiere::query()->create(['code' => 'JS', 'label' => 'JavaScript', 'active' => true]);
        $net = Competence::query()->create(['label' => '.NET', 'active' => true]);
        $old = Competence::query()->create(['label' => 'Cobol', 'active' => true]);
        $retired = Competence::query()->create(['label' => 'Flash', 'active' => false]);
        $id = $this->create()['data']['id'];

        $this->putJson("/api/v1/companies/{$id}/profiles", ['filiere_ids' => [$java->id], 'competence_ids' => [$old->id]])->assertOk();
        $old->forceFill(['active' => false])->save();

        // L'association devenue inactive est conservée quand on ajoute d'autres valeurs.
        $this->putJson("/api/v1/companies/{$id}/profiles", ['filiere_ids' => [$java->id, $js->id], 'competence_ids' => [$old->id, $net->id]])
            ->assertOk()->assertJsonCount(2, 'data.competences');
        $this->assertEqualsCanonicalizing([$old->id, $net->id], Company::query()->findOrFail($id)->competences()->pluck('competences.id')->all());

        // Une NOUVELLE valeur inactive est refusée.
        $this->putJson("/api/v1/companies/{$id}/profiles", ['filiere_ids' => [], 'competence_ids' => [$retired->id]])
            ->assertStatus(422)->assertJsonValidationErrors('competence_ids');

        // Retrait volontaire.
        $this->putJson("/api/v1/companies/{$id}/profiles", ['filiere_ids' => [], 'competence_ids' => [$net->id]])->assertOk();
        $this->assertSame([$net->id], Company::query()->findOrFail($id)->competences()->pluck('competences.id')->all());

        $log = AuditLog::query()->where('action', AuditAction::CompanyProfilesUpdated->value)->latest('id')->firstOrFail();
        $this->assertSame(['.NET'], $log->new_values['competences'] ?? null);
        $this->putJson("/api/v1/companies/{$id}/profiles", ['filiere_ids' => [$java->id, $java->id], 'competence_ids' => []])->assertStatus(422);
    }

    // ---------------------------------------------------------------- Fiche de l'entreprise (ENT-08)

    public function test_l_entreprise_modifie_seulement_sa_description_et_son_site(): void
    {
        $mine = $this->makeCompany('Mienne');
        $other = $this->makeCompany('Autre');
        $this->actingAs($mine->user, 'web');

        $this->putJson('/api/v1/me/company', [
            'description' => 'Nous recrutons.', 'website' => 'https://mienne.test', 'name' => 'Pirate', 'company_id' => $other->id,
        ])->assertOk()->assertJsonPath('data.description', 'Nous recrutons.');

        $this->assertSame('Mienne', $mine->fresh()?->name);
        $this->assertNull($other->fresh()?->description);
        $this->putJson('/api/v1/me/company', ['website' => 'javascript:alert(1)'])->assertStatus(422);
        $this->assertSame($mine->user_id, AuditLog::query()->where('action', AuditAction::CompanyUpdated->value)->value('user_id'));

        // Garde Sanctum mémorisé entre deux requêtes d'un même test : oublié avant de changer d'utilisateur.
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->withRole(Role::Cme)->create(), 'web');
        $this->getJson('/api/v1/me/company')->assertForbidden();
    }

    public function test_categorie_entreprises_dans_le_journal(): void
    {
        $this->create();

        $this->getJson('/api/v1/audit-logs?category=entreprises')->assertOk()->assertJsonPath('data.0.action', 'company.created');
    }

    private function makeCompany(string $name = 'Acme'): Company
    {
        $user = User::factory()->withRole(Role::Entreprise)->create();

        return Company::query()->forceCreate(['user_id' => $user->id, 'name' => $name, 'credentials_version' => 1]);
    }
}
