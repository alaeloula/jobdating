<?php

namespace Tests\Feature\Cv;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\CvConsultationController;
use App\Models\AuditLog;
use App\Models\CvDocument;
use App\Models\Learner;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Filiere;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\SchoolClass;
use App\Models\User;
use App\Services\Cv\CvStorage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakePdf;
use Tests\TestCase;

/**
 * Consultation du CV par lien temporaire, journalisée (CV-03, RG-CV-01 à 03, APP-03 ; plan T-21 v1.2).
 * PDF fictifs uniquement.
 */
class CvConsultationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $cme;

    private User $direction;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake(CvStorage::DISK);
        $this->admin = User::factory()->withRole(Role::Administrateur)->create();
        $this->cme = User::factory()->withRole(Role::Cme)->create();
        $this->direction = User::factory()->withRole(Role::Direction)->create();
        $this->withHeader('Origin', 'http://localhost');
        config(['cv.transaction_base' => DB::transactionLevel()]);
    }

    private function learner(bool $active = true): Learner
    {
        $n = ++$this->sequence;
        $class = SchoolClass::query()->first() ?? SchoolClass::query()->create([
            'name' => 'CLASSE-FICTIVE',
            'filiere_id' => Filiere::query()->create(['code' => 'FIC', 'label' => 'Fictive'])->id,
        ]);
        $user = User::factory()->withRole(Role::Apprenant)->create([
            'first_name' => 'Prénom'.$n, 'last_name' => 'Fictif', 'email' => "apprenant{$n}@example.test",
            'deactivated_at' => $active ? null : now(),
        ]);

        return Learner::query()->forceCreate([
            'user_id' => $user->id,
            'cnie' => 'ZZ'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'campus_id' => (Campus::query()->first() ?? Campus::query()->create(['name' => 'Campus Fictif']))->id,
            'promotion_id' => (Promotion::query()->first() ?? Promotion::query()->create(['label' => 'Promotion Fictive']))->id,
            'class_id' => $class->id,
        ]);
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    private function upload(Learner $learner, string $pdf): void
    {
        $this->as($this->admin)->post("/api/v1/learners/{$learner->id}/cv", ['file' => FakePdf::upload($pdf)], ['Accept' => 'application/json'])->assertSuccessful();
    }

    private function link(User $viewer, Learner $learner): string
    {
        return (string) $this->as($viewer)->postJson("/api/v1/learners/{$learner->id}/cv/link")->assertOk()->json('url');
    }

    /** Lien signé fabriqué pour un compte quelconque (pour prouver que les droits sont relus au service). */
    private function signed(User $viewer, CvDocument $cv, int $minutes = 15): string
    {
        return URL::temporarySignedRoute('cv.file', now()->addMinutes($minutes), ['cv' => $cv->id, 'user' => $viewer->id, 'version' => $cv->version], false);
    }

    private function fetch(User $viewer, string $url): TestResponse
    {
        return $this->as($viewer)->get($url, ['Accept' => 'application/json']);
    }

    private function views(): int
    {
        return AuditLog::query()->whereIn('action', [AuditAction::CvViewed->value, AuditAction::CvDownloaded->value])->count();
    }

    // ---------------------------------------------------------------- Lien

    public function test_lien_selon_le_role(): void
    {
        $learner = $this->learner();
        $this->as($this->admin)->postJson("/api/v1/learners/{$learner->id}/cv/link")->assertNotFound()->assertJsonPath('code', 'no_cv');
        $this->upload($learner, FakePdf::classic());

        foreach ([$this->admin, $this->cme, $this->direction] as $user) {
            $response = $this->as($user)->postJson("/api/v1/learners/{$learner->id}/cv/link")->assertOk();
            $this->assertStringStartsWith('/api/v1/cv-files/', (string) $response->json('url'));
            $this->assertSame(CvDocument::query()->sole()->version, $response->json('version'));
        }
        $company = User::factory()->withRole(Role::Entreprise)->create();
        $this->as($company)->postJson("/api/v1/learners/{$learner->id}/cv/link")->assertForbidden();
        $this->as($learner->user)->postJson("/api/v1/learners/{$learner->id}/cv/link")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/learners/{$learner->id}/cv/link")->assertUnauthorized();
        $this->assertSame(0, $this->views());
    }

    // ---------------------------------------------------------------- Fichier et journal

    public function test_fichier_servi_isole_et_journalise(): void
    {
        $learner = $this->learner();
        $pdf = FakePdf::classic();
        $this->upload($learner, $pdf);
        $url = $this->link($this->direction, $learner);

        $response = $this->fetch($this->direction, $url)->assertOk();
        $this->assertSame($pdf, $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('CV-Prenom1-Fictif.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame("sandbox; default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $log = AuditLog::query()->where('action', AuditAction::CvViewed->value)->sole();
        $this->assertSame($learner->id, $log->subject_id);
        $this->assertSame(CvDocument::query()->sole()->version, $log->new_values['version']);
        $this->assertNotNull($log->ip);
        $this->assertStringNotContainsString('signature', (string) json_encode($log->toArray()));

        $this->fetch($this->direction, $url.'&download=1')->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::CvDownloaded->value)->count());
    }

    public function test_lien_lie_au_compte_et_a_la_signature(): void
    {
        $learner = $this->learner();
        $this->upload($learner, FakePdf::classic());
        $url = $this->link($this->admin, $learner);
        $cv = CvDocument::query()->sole();

        $this->fetch($this->cme, $url)->assertForbidden()->assertJsonPath('code', 'invalid_link');
        $this->fetch($this->admin, $url.'&download=2')->assertForbidden()->assertJsonPath('code', 'invalid_link');
        foreach ([
            'signature' => preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $url),
            'user' => str_replace('user='.$this->admin->id, 'user='.$this->cme->id, $url),
            'version' => str_replace($cv->version, '00000000-0000-4000-8000-000000000000', $url),
            'expires' => preg_replace('/expires=(\d+)/', 'expires=9999999999', $url),
        ] as $field => $forged) {
            $this->assertNotSame($url, $forged, $field);
            $this->fetch($this->admin, (string) $forged)->assertForbidden()->assertJsonPath('code', 'invalid_link');
        }
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
        $this->assertSame(0, $this->views());
    }

    public function test_lien_expire_apres_15_minutes(): void
    {
        $learner = $this->learner();
        $this->upload($learner, FakePdf::classic());
        $url = $this->link($this->cme, $learner);

        $this->travel(14)->minutes();
        $this->fetch($this->cme, $url)->assertOk();
        $this->travel(2)->minutes();
        $this->fetch($this->cme, $url)->assertStatus(410)->assertJsonPath('code', 'link_expired');
        $this->assertSame(1, $this->views());
    }

    // ---------------------------------------------------------------- Version

    public function test_version_remplacement_retour_et_retrait(): void
    {
        $learner = $this->learner();
        $a = FakePdf::classic('Version A');
        $this->upload($learner, $a);
        $first = $this->link($this->cme, $learner);
        $versionA = CvDocument::query()->sole()->version;

        // Même fichier : version inchangée, lien toujours valable.
        $this->upload($learner, $a);
        $this->assertSame($versionA, CvDocument::query()->sole()->version);
        $this->fetch($this->cme, $first)->assertOk();

        // A → B → A : trois versions ; le premier lien ne remarche jamais.
        $this->upload($learner, FakePdf::classic('Version B'));
        $this->fetch($this->cme, $first)->assertNotFound()->assertJsonPath('code', 'cv_outdated');
        $this->upload($learner, $a);
        $this->assertNotSame($versionA, CvDocument::query()->sole()->version);
        $this->fetch($this->cme, $first)->assertNotFound()->assertJsonPath('code', 'cv_outdated');

        $current = $this->link($this->cme, $learner);
        $this->as($this->admin)->deleteJson("/api/v1/learners/{$learner->id}/cv")->assertOk();
        $this->fetch($this->cme, $current)->assertNotFound()->assertJsonPath('code', 'cv_outdated');
        $this->assertSame(1, $this->views());
    }

    // ---------------------------------------------------------------- Droits relus au service

    public function test_droits_relus_au_service(): void
    {
        $learner = $this->learner();
        $this->upload($learner, FakePdf::classic());
        $forCme = $this->link($this->cme, $learner);
        $forAdmin = $this->link($this->admin, $learner);

        // Cible supprimée après l'émission : CME 404, administrateur toujours servi.
        $learner->user->delete();
        $this->fetch($this->cme, $forCme)->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->fetch($this->admin, $forAdmin)->assertOk();
        $learner->user->restore();

        // Lecteur qui change de rôle (perd ses droits) après l'émission.
        $forDirection = $this->link($this->direction, $learner);
        $this->direction->syncRoles([Role::Entreprise->value]);
        $this->fetch($this->direction->fresh() ?? $this->direction, $forDirection)->assertNotFound()->assertJsonPath('code', 'not_found');

        // Entreprise avec un lien correctement signé à son nom : refusée en T-21 (T-24).
        $company = User::factory()->withRole(Role::Entreprise)->create();
        $this->fetch($company, $this->signed($company, CvDocument::query()->sole()))->assertNotFound();
        $this->assertSame(1, $this->views());
    }

    /** Revue Codex T-21, suggestion 5 : droits et lecteur changés après l'émission, sans changement de rôle. */
    public function test_permission_retiree_et_lecteur_desactive_ou_supprime_apres_emission(): void
    {
        $learner = $this->learner();
        $this->upload($learner, FakePdf::classic());

        // Permission `cv.view` retirée au rôle Direction, rôle inchangé : 404.
        $url = $this->link($this->direction, $learner);
        SpatieRole::findByName(Role::Direction->value, 'web')->revokePermissionTo(Permission::CvView->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->fetch($this->direction->fresh() ?? $this->direction, $url)->assertNotFound()->assertJsonPath('code', 'not_found');

        // Lecteur désactivé après l'émission : la session est refusée (401).
        $url = $this->link($this->cme, $learner);
        $this->cme->forceFill(['deactivated_at' => now()])->save();
        $this->fetch($this->cme, $url)->assertUnauthorized();

        // Lecteur supprimé après l'émission : refusé par CvAccess (404).
        $other = User::factory()->withRole(Role::Cme)->create();
        $url = $this->link($other, $learner);
        $other->delete();
        $this->fetch($other, $url)->assertNotFound();
        $this->assertSame(0, $this->views());
    }

    /** Revue Codex T-21, suggestion 5 : chemin modifié, et signature fausse sur un lien expiré (403, jamais 410). */
    public function test_chemin_modifie_et_signature_fausse_expiree(): void
    {
        $first = $this->learner();
        $second = $this->learner();
        $this->upload($first, FakePdf::classic('A'));
        $this->upload($second, FakePdf::classic('B'));
        $url = $this->link($this->admin, $first);
        $secondId = CvDocument::query()->where('learner_id', $second->id)->value('id');

        $this->fetch($this->admin, (string) preg_replace('#/cv-files/\d+#', '/cv-files/'.$secondId, $url))->assertForbidden()->assertJsonPath('code', 'invalid_link');

        $this->travel(20)->minutes();
        $forged = (string) preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('f', 64), $url);
        $this->fetch($this->admin, $forged)->assertForbidden()->assertJsonPath('code', 'invalid_link');
        $this->fetch($this->admin, $url)->assertStatus(410)->assertJsonPath('code', 'link_expired');
        $this->assertSame(0, $this->views());
    }

    public function test_apprenant_desactive_lu_par_les_trois_roles(): void
    {
        $learner = $this->learner(active: false);
        $this->upload($learner, FakePdf::classic());
        foreach ([$this->admin, $this->cme, $this->direction] as $user) {
            $this->fetch($user, $this->link($user, $learner))->assertOk();
        }
    }

    // ---------------------------------------------------------------- Mon CV (apprenant)

    public function test_mon_cv(): void
    {
        $learner = $this->learner();
        $other = $this->learner();
        $this->as($learner->user)->getJson('/api/v1/me/cv')->assertOk()->assertJsonPath('data', null);
        $this->as($learner->user)->postJson('/api/v1/me/cv/link')->assertNotFound()->assertJsonPath('code', 'no_cv');

        $this->upload($learner, FakePdf::classic('Mon CV'));
        $this->upload($other, FakePdf::classic('Autre CV'));
        $state = $this->as($learner->user)->getJson('/api/v1/me/cv')->assertOk();
        $this->assertSame(['version', 'source', 'added_at'], array_keys($state->json('data')));

        $url = (string) $this->as($learner->user)->postJson('/api/v1/me/cv/link')->assertOk()->json('url');
        $this->assertSame(FakePdf::classic('Mon CV'), $this->fetch($learner->user, $url)->assertOk()->getContent());

        // CV d'un autre apprenant par la route fichier, même avec un lien signé à son nom : 404.
        $otherCv = CvDocument::query()->where('learner_id', $other->id)->sole();
        $this->fetch($learner->user, $this->signed($learner->user, $otherCv))->assertNotFound();
        $this->as($this->admin)->getJson('/api/v1/me/cv')->assertForbidden();
    }

    // ---------------------------------------------------------------- Erreurs

    public function test_fichier_illisible_503_sans_journal(): void
    {
        $learner = $this->learner();
        $this->upload($learner, FakePdf::classic());
        $url = $this->link($this->admin, $learner);
        Storage::disk(CvStorage::DISK)->put(CvDocument::query()->sole()->path, 'contenu-abime');

        $this->fetch($this->admin, $url)->assertStatus(503)->assertJsonPath('code', 'cv_unavailable');
        $this->assertSame(0, $this->views());
    }

    public function test_echec_du_journal_empeche_l_envoi(): void
    {
        $learner = $this->learner();
        $this->upload($learner, FakePdf::classic());
        $url = $this->link($this->admin, $learner);
        AuditLog::creating(function (AuditLog $log): void {
            if ($log->action === AuditAction::CvViewed) {
                throw new RuntimeException('journal indisponible');
            }
        });

        $response = $this->fetch($this->admin, $url)->assertStatus(500);
        $this->assertStringNotContainsString('%PDF', (string) $response->getContent());
    }

    public function test_nom_de_fichier_neutralise(): void
    {
        $learner = $this->learner();
        $learner->user->forceFill(['first_name' => "Éloïse\r\n/../", 'last_name' => 'Ñúñez "x"'])->save();
        $this->assertSame('CV-Eloise-Nunez-x.pdf', CvConsultationController::fileName($learner->fresh(['user']) ?? $learner));

        $learner->user->forceFill(['first_name' => '###', 'last_name' => '***'])->save();
        $this->assertSame('CV.pdf', CvConsultationController::fileName($learner->fresh(['user']) ?? $learner));
    }
}
