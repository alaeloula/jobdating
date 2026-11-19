<?php

namespace Tests\Feature\Accounts;

use App\Enums\AuditAction;
use App\Enums\LinkRequest;
use App\Enums\PasswordTokenType;
use App\Enums\Role;
use App\Jobs\SendPasswordLink;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\GeneratedPasswordNotification;
use App\Services\Accounts\AccountRuleException;
use App\Services\Accounts\StaffAccountService;
use App\Services\Passwords\PasswordGenerator;
use App\Services\Passwords\PasswordTokenService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Comptes de l'équipe (ADM-01, T-10) : droits, liste, création, modification, désactivation,
 * suppression et restauration, protections, lien d'activation, réinitialisation par l'administrateur.
 */
class StaffAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->withRole(Role::Administrateur)->create(['first_name' => 'Ada', 'last_name' => 'Admin']);
        $this->withHeader('Origin', 'http://localhost');
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->admin, 'web');
    }

    // ---------------------------------------------------------------- Droits

    /**
     * @return iterable<string, array{Role}>
     */
    public static function nonAdmins(): iterable
    {
        yield 'cme' => [Role::Cme];
        yield 'direction' => [Role::Direction];
        yield 'entreprise' => [Role::Entreprise];
        yield 'apprenant' => [Role::Apprenant];
    }

    #[DataProvider('nonAdmins')]
    public function test_reserve_a_l_administrateur(Role $role): void
    {
        $target = User::factory()->withRole(Role::Cme)->create();
        $this->actingAs(User::factory()->withRole($role)->create(), 'web');

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/users', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'x@example.test', 'role' => 'cme'])->assertForbidden();
        $this->patchJson("/api/v1/users/{$target->id}", ['first_name' => 'Autre'])->assertForbidden();
        $this->postJson("/api/v1/users/{$target->id}/deactivate")->assertForbidden();
        $this->deleteJson("/api/v1/users/{$target->id}")->assertForbidden();
        $this->postJson("/api/v1/users/{$target->id}/reset-password")->assertForbidden();

        $this->assertNull($target->fresh()?->deactivated_at);
        $this->assertNotSame('Autre', $target->fresh()->first_name);
    }

    public function test_sans_session(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- Liste

    public function test_liste_limitee_aux_comptes_de_l_equipe_avec_statut(): void
    {
        User::factory()->withRole(Role::Cme)->create(['first_name' => 'Camille', 'last_name' => 'Active']);
        User::factory()->unactivated()->withRole(Role::Cme)->create(['first_name' => 'Ines', 'last_name' => 'Invitee']);
        User::factory()->deactivated()->withRole(Role::Direction)->create(['first_name' => 'Dan', 'last_name' => 'Desactive']);
        User::factory()->withRole(Role::Direction)->create(['first_name' => 'Sam', 'last_name' => 'Supprime'])->delete();
        $company = User::factory()->withRole(Role::Entreprise)->create();
        $learner = User::factory()->withRole(Role::Apprenant)->create();
        $this->asAdmin();

        $rows = collect($this->getJson('/api/v1/users')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing(
            ['Active' => 'actif', 'Admin' => 'actif', 'Desactive' => 'desactive', 'Invitee' => 'invitation'],
            $rows->pluck('status', 'last_name')->all(),
        );
        $this->assertTrue($rows->firstWhere('last_name', 'Admin')['is_self']);
        $this->assertArrayNotHasKey('password', $rows->first());

        $this->assertSame(['Supprime'], array_column($this->getJson('/api/v1/users?status=supprime')->json('data'), 'last_name'));
        $this->assertSame(['Invitee'], array_column($this->getJson('/api/v1/users?status=invitation')->json('data'), 'last_name'));
        $this->assertSame(['Desactive'], array_column($this->getJson('/api/v1/users?role=direction')->json('data'), 'last_name'));
        $this->assertSame(['Active'], array_column($this->getJson('/api/v1/users?search=camille%20act')->json('data'), 'last_name'));
        $this->getJson('/api/v1/users?role=entreprise')->assertStatus(422);

        // Entreprise et apprenant : hors périmètre, traités comme inexistants (RG-ACC-02).
        $this->patchJson("/api/v1/users/{$company->id}", ['first_name' => 'X'])->assertNotFound();
        $this->postJson("/api/v1/users/{$learner->id}/deactivate")->assertNotFound();
    }

    public function test_pagination(): void
    {
        User::factory()->count(30)->withRole(Role::Cme)->create();
        $this->asAdmin();

        $this->getJson('/api/v1/users')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.total', 31);
        $this->getJson('/api/v1/users?page=2')->assertOk()->assertJsonCount(6, 'data');
    }

    // ---------------------------------------------------------------- Création et modification

    public function test_creation_envoie_le_lien_d_activation_apres_validation(): void
    {
        Queue::fake();
        $this->asAdmin();

        $this->postJson('/api/v1/users', [
            'first_name' => 'Nora', 'last_name' => 'Nouvelle', 'email' => 'nora@example.test', 'phone' => '0600000000', 'role' => 'direction',
        ])->assertCreated()->assertJsonPath('data.status', 'invitation')->assertJsonPath('data.role', 'direction');

        $user = User::query()->where('email', 'nora@example.test')->firstOrFail();
        $this->assertNull($user->activated_at);
        $this->assertSame(Role::Direction, $user->currentRole());
        Queue::assertPushed(SendPasswordLink::class, fn (SendPasswordLink $job) => $job->userId === $user->id
            && $job->adminId === $this->admin->id && $job->request === LinkRequest::ActivationByAdmin);

        $this->assertNotNull(AuditLog::query()->where('action', AuditAction::AccountCreated->value)->where('subject_id', $user->id)->first());
        $this->assertSame($this->admin->id, AuditLog::query()->where('action', AuditAction::RoleChanged->value)->where('subject_id', $user->id)->value('user_id'));
    }

    public function test_validation_creation(): void
    {
        $deleted = User::factory()->withRole(Role::Cme)->create(['email' => 'pris@example.test']);
        $deleted->delete();
        $this->asAdmin();

        $this->postJson('/api/v1/users', [])->assertStatus(422)->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'role']);
        $this->postJson('/api/v1/users', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'PRIS@example.test', 'role' => 'cme'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/users', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.test', 'role' => 'entreprise'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->postJson('/api/v1/users', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'pas-une-adresse', 'role' => 'cme'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_modification_et_changement_de_role_journalise(): void
    {
        $cme = User::factory()->withRole(Role::Cme)->create(['email' => 'cme@example.test']);
        $this->asAdmin();

        $this->patchJson("/api/v1/users/{$cme->id}", ['first_name' => 'Corrige', 'phone' => '0611111111', 'role' => 'direction'])
            ->assertOk()->assertJsonPath('data.first_name', 'Corrige')->assertJsonPath('data.role', 'direction');

        $this->assertSame(Role::Direction, $cme->fresh()?->currentRole());
        $role = AuditLog::query()->where('action', AuditAction::RoleChanged->value)->where('subject_id', $cme->id)->latest('id')->firstOrFail();
        $this->assertEquals(['role' => 'cme'], $role->old_values);
        $this->assertEquals(['role' => 'direction'], $role->new_values);
        $this->assertNotNull(AuditLog::query()->where('action', AuditAction::AccountUpdated->value)->where('subject_id', $cme->id)->first());
    }

    // ---------------------------------------------------------------- Protections

    public function test_son_propre_compte_ne_peut_etre_ni_desactive_ni_supprime_ni_change_de_role(): void
    {
        User::factory()->withRole(Role::Administrateur)->create();
        $this->asAdmin();

        $this->postJson("/api/v1/users/{$this->admin->id}/deactivate")->assertStatus(422)->assertJsonPath('code', 'own_account');
        $this->deleteJson("/api/v1/users/{$this->admin->id}")->assertStatus(422)->assertJsonPath('code', 'own_account');
        $this->patchJson("/api/v1/users/{$this->admin->id}", ['role' => 'cme'])->assertStatus(422)->assertJsonPath('code', 'own_account');
        // Son nom peut être corrigé.
        $this->patchJson("/api/v1/users/{$this->admin->id}", ['first_name' => 'Adèle'])->assertOk();

        $fresh = $this->admin->fresh();
        $this->assertNull($fresh?->deactivated_at);
        $this->assertFalse($fresh->trashed());
        $this->assertSame(Role::Administrateur, $fresh->currentRole());
    }

    public function test_il_reste_toujours_un_administrateur_actif(): void
    {
        // Seul administrateur actif : le compte cible ; l'appelant n'est pas administrateur (service).
        $only = User::factory()->withRole(Role::Administrateur)->create();
        $this->admin->forceFill(['deactivated_at' => now()])->save();
        $service = app(StaffAccountService::class);
        $other = User::factory()->withRole(Role::Cme)->create();

        foreach ([
            fn () => $service->deactivate($only, $other),
            fn () => $service->delete($only, $other),
            fn () => $service->update($only, ['role' => 'cme'], $other),
        ] as $action) {
            try {
                $action();
                $this->fail('Dernier administrateur retiré.');
            } catch (AccountRuleException $e) {
                $this->assertSame('last_admin', $e->errorCode);
            }
        }

        // Avec un second administrateur actif, l'action est permise.
        $this->admin->forceFill(['deactivated_at' => null])->save();
        $service->deactivate($only, $other);
        $this->assertNotNull($only->fresh()?->deactivated_at);
    }

    /** Revue Codex T-10, constat 1 : une invitation d'administrateur n'est pas un administrateur actif. */
    public function test_une_invitation_d_administrateur_ne_compte_pas_comme_administrateur_actif(): void
    {
        // B : seul administrateur activé restant (A vient d'être désactivé) ; C : invitation d'administrateur.
        $only = User::factory()->withRole(Role::Administrateur)->create();
        User::factory()->unactivated()->withRole(Role::Administrateur)->create();
        $this->admin->forceFill(['deactivated_at' => now()])->save();
        $service = app(StaffAccountService::class);
        $actor = User::factory()->withRole(Role::Cme)->create();

        try {
            $service->deactivate($only, $actor);
            $this->fail('Le dernier administrateur activé a été désactivé.');
        } catch (AccountRuleException $e) {
            $this->assertSame('last_admin', $e->errorCode);
        }
        $this->assertNull($only->fresh()?->deactivated_at);

        // Une invitation d'administrateur peut, elle, être désactivée ou supprimée librement.
        $invited = User::factory()->unactivated()->withRole(Role::Administrateur)->create();
        $service->delete($invited, $actor);
        $this->assertSoftDeleted('users', ['id' => $invited->id]);
    }

    /** Revue Codex T-10, constat 4 : la recherche « 0 » filtre réellement. */
    public function test_la_recherche_zero_filtre(): void
    {
        User::factory()->withRole(Role::Cme)->create(['first_name' => 'Neutre', 'last_name' => 'Compte', 'email' => 'neutral@example.test']);
        User::factory()->withRole(Role::Cme)->create(['first_name' => 'Revue', 'last_name' => 'Zero', 'email' => 'review0@example.test']);
        $this->asAdmin();

        $this->assertSame(['review0@example.test'], array_column($this->getJson('/api/v1/users?search=0')->assertOk()->json('data'), 'email'));
    }

    // ---------------------------------------------------------------- Désactivation et suppression

    public function test_desactivation_ferme_les_sessions_et_bloque_la_connexion(): void
    {
        $cme = User::factory()->withRole(Role::Cme)->create(['email' => 'cme@example.test']);
        DB::table('sessions')->insert(['id' => 'session-cme', 'user_id' => $cme->id, 'payload' => '', 'last_activity' => time()]);
        app(PasswordTokenService::class)->issue($cme, PasswordTokenType::Reset);
        $this->asAdmin();

        $this->postJson("/api/v1/users/{$cme->id}/deactivate")->assertOk()->assertJsonPath('data.status', 'desactive');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $cme->id)->count());
        $this->assertSame(0, DB::table('password_tokens')->where('user_id', $cme->id)->count());
        $this->assertNotNull(AuditLog::query()->where('action', AuditAction::AccountDeactivated->value)->where('subject_id', $cme->id)->first());

        $this->postJson("/api/v1/users/{$cme->id}/reactivate")->assertOk()->assertJsonPath('data.status', 'actif');
        $this->assertNotNull(AuditLog::query()->where('action', AuditAction::AccountReactivated->value)->where('subject_id', $cme->id)->first());
    }

    public function test_un_compte_desactive_ne_peut_plus_se_connecter(): void
    {
        $cme = User::factory()->deactivated()->withRole(Role::Cme)->create(['email' => 'cme@example.test']);

        $this->postJson('/api/v1/login', ['identifier' => 'cme@example.test', 'password' => 'password'])
            ->assertStatus(403)->assertJsonPath('code', 'account_deactivated');
        $this->assertNotNull($cme->fresh()?->deactivated_at);
    }

    public function test_suppression_logique_puis_restauration(): void
    {
        $cme = User::factory()->withRole(Role::Cme)->create(['email' => 'cme@example.test']);
        DB::table('sessions')->insert(['id' => 'session-cme', 'user_id' => $cme->id, 'payload' => '', 'last_activity' => time()]);
        $this->asAdmin();

        $this->deleteJson("/api/v1/users/{$cme->id}")->assertNoContent();
        $this->assertSoftDeleted('users', ['id' => $cme->id]);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $cme->id)->count());
        $this->assertSame(['supprime'], array_column($this->getJson('/api/v1/users?status=supprime')->json('data'), 'status'));

        // Supprimé : seule la restauration est possible.
        $this->patchJson("/api/v1/users/{$cme->id}", ['first_name' => 'X'])->assertStatus(422)->assertJsonPath('code', 'account_deleted');
        $this->postJson("/api/v1/users/{$cme->id}/restore")->assertOk()->assertJsonPath('data.status', 'actif');

        $this->assertFalse((bool) $cme->fresh()?->trashed());
        $this->assertNotNull(AuditLog::query()->where('action', AuditAction::AccountDeleted->value)->where('subject_id', $cme->id)->first());
        $this->assertNotNull(AuditLog::query()->where('action', AuditAction::AccountRestored->value)->where('subject_id', $cme->id)->first());
    }

    // ---------------------------------------------------------------- Lien d'activation

    public function test_renvoyer_le_lien_seulement_pour_un_compte_non_active(): void
    {
        Queue::fake();
        $invited = User::factory()->unactivated()->withRole(Role::Cme)->create();
        $active = User::factory()->withRole(Role::Cme)->create();
        $this->asAdmin();

        $this->postJson("/api/v1/users/{$invited->id}/resend-activation")->assertStatus(202);
        Queue::assertPushed(SendPasswordLink::class, fn (SendPasswordLink $job) => $job->userId === $invited->id);

        $this->postJson("/api/v1/users/{$active->id}/resend-activation")->assertStatus(422)->assertJsonPath('code', 'not_activatable');
    }

    // ---------------------------------------------------------------- Réinitialisation par l'administrateur

    public function test_reinitialisation_mot_de_passe_affiche_une_fois_et_envoye(): void
    {
        Notification::fake();
        $cme = User::factory()->withRole(Role::Cme)->create(['email' => 'cme@example.test']);
        DB::table('sessions')->insert(['id' => 'session-cme', 'user_id' => $cme->id, 'payload' => '', 'last_activity' => time()]);
        app(PasswordTokenService::class)->issue($cme, PasswordTokenType::Reset);
        $this->asAdmin();

        $response = $this->postJson("/api/v1/users/{$cme->id}/reset-password")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $password = (string) $response->json('data.password');

        $this->assertSame(PasswordGenerator::LENGTH, strlen($password));
        $this->assertTrue(Hash::check($password, (string) $cme->fresh()?->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $cme->id)->count());
        $this->assertSame(0, DB::table('password_tokens')->where('user_id', $cme->id)->count());

        Notification::assertSentTo($cme, GeneratedPasswordNotification::class, function (GeneratedPasswordNotification $n) use ($cme, $password) {
            return in_array($password, $n->toMail($cme)->introLines, true);
        });

        $log = AuditLog::query()->where('action', AuditAction::PasswordReset->value)->where('subject_id', $cme->id)->firstOrFail();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertEquals(['note' => 'Réinitialisé par l’administrateur'], $log->new_values);
        $this->assertStringNotContainsString($password, json_encode(AuditLog::query()->get()->toArray(), JSON_THROW_ON_ERROR));
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::AccountUpdated->value)->where('subject_id', $cme->id)->count());

        // Le mot de passe n'est jamais relisible : aucune route ne le renvoie.
        $this->assertStringNotContainsString($password, (string) $this->getJson('/api/v1/users')->getContent());
    }

    public function test_reinitialisation_refusee_pour_un_compte_non_active_ou_desactive(): void
    {
        $invited = User::factory()->unactivated()->withRole(Role::Cme)->create();
        $off = User::factory()->deactivated()->withRole(Role::Cme)->create();
        $this->asAdmin();

        $this->postJson("/api/v1/users/{$invited->id}/reset-password")->assertStatus(422)->assertJsonPath('code', 'not_resettable');
        $this->postJson("/api/v1/users/{$off->id}/reset-password")->assertStatus(422)->assertJsonPath('code', 'not_resettable');
    }

    public function test_e_mail_du_mot_de_passe_chiffre_dans_la_file(): void
    {
        config(['queue.default' => 'database']);
        $cme = User::factory()->withRole(Role::Cme)->create();
        $this->asAdmin();

        $password = (string) $this->postJson("/api/v1/users/{$cme->id}/reset-password")->assertOk()->json('data.password');

        $payloads = DB::table('jobs')->pluck('payload')->implode('|');
        $this->assertNotSame('', $payloads);
        $this->assertStringNotContainsString($password, $payloads);
    }

    public function test_generateur_conforme_a_la_regle(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $password = PasswordGenerator::generate();
            $this->assertSame(1, preg_match('/^[a-km-zA-HJ-NP-Z2-9]{16}$/', $password));
            $seen[$password] = true;
        }
        $this->assertCount(50, $seen);
    }
}
