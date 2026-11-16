<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Événements journalisés dès T-06 : comptes, rôles, connexions (décision du 16/11/2026).
 */
class AuditEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function lastOf(AuditAction $action): ?AuditLog
    {
        return AuditLog::query()->where('action', $action->value)->latest('id')->first();
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function login(string $identifier, string $password = 'password', string $ip = '10.0.0.1'): TestResponse
    {
        return $this->withHeader('Origin', 'http://localhost')
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/login', ['identifier' => $identifier, 'password' => $password]);
    }

    public function test_creation_de_compte_et_changement_de_role(): void
    {
        $user = User::factory()->create(['email' => 'cme@example.test']);
        $user->setRole(Role::Cme);
        $user->setRole(Role::Direction);

        $created = $this->lastOf(AuditAction::AccountCreated);
        $this->assertSame($user->id, $created?->subject_id);
        $this->assertSame('cme@example.test', $created->new_values['email'] ?? null);
        $this->assertArrayNotHasKey('password', $created->new_values ?? []);

        $roles = AuditLog::query()->where('action', AuditAction::RoleChanged->value)->orderBy('id')->get();
        $this->assertCount(2, $roles);
        $this->assertEquals(['role' => null], $roles[0]->old_values);
        $this->assertEquals(['role' => 'cme'], $roles[0]->new_values);
        $this->assertEquals(['role' => 'cme'], $roles[1]->old_values);
        $this->assertEquals(['role' => 'direction'], $roles[1]->new_values);
    }

    public function test_le_meme_role_reattribue_n_est_pas_journalise(): void
    {
        $user = User::factory()->withRole(Role::Cme)->create();
        $user->setRole(Role::Cme);

        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::RoleChanged->value)->count());
    }

    public function test_desactivation_reactivation_modification_suppression_restauration(): void
    {
        $user = User::factory()->withRole(Role::Apprenant)->create(['phone' => '0600000000']);

        $user->forceFill(['deactivated_at' => now()])->save();
        $this->assertNotNull($this->lastOf(AuditAction::AccountDeactivated));

        $user->forceFill(['deactivated_at' => null])->save();
        $this->assertNotNull($this->lastOf(AuditAction::AccountReactivated));

        $user->forceFill(['phone' => '0611111111', 'password' => 'nouveau-mot-de-passe'])->save();
        $updated = $this->lastOf(AuditAction::AccountUpdated);
        $this->assertEquals(['phone' => '0600000000'], $updated?->old_values);
        $this->assertEquals(['phone' => '0611111111', 'note' => 'Mot de passe modifié'], $updated->new_values);

        $user->delete();
        $this->assertSame($user->id, $this->lastOf(AuditAction::AccountDeleted)?->subject_id);

        $user->restore();
        $this->assertSame($user->id, $this->lastOf(AuditAction::AccountRestored)?->subject_id);
    }

    public function test_la_mise_a_jour_de_la_derniere_connexion_n_est_pas_journalisee_comme_modification(): void
    {
        $user = User::factory()->withRole(Role::Entreprise)->create(['username' => 'societe-fictive']);
        $before = AuditLog::query()->where('action', AuditAction::AccountUpdated->value)->count();

        $this->login('societe-fictive')->assertOk();

        $this->assertSame($before, AuditLog::query()->where('action', AuditAction::AccountUpdated->value)->count());
        $this->assertSame($user->id, $this->lastOf(AuditAction::LoginSucceeded)?->user_id);
    }

    public function test_connexion_reussie_et_deconnexion(): void
    {
        $user = User::factory()->withRole(Role::Cme)->create(['email' => 'cme@example.test']);

        $this->login('cme@example.test')->assertOk();
        $success = $this->lastOf(AuditAction::LoginSucceeded);
        $this->assertSame($user->id, $success?->user_id);
        $this->assertSame('10.0.0.1', $success->ip);

        $this->actingAs($user, 'web')->postJson('/api/v1/logout')->assertNoContent();
        $this->assertSame($user->id, $this->lastOf(AuditAction::Logout)?->user_id);
    }

    public function test_echecs_de_connexion_avec_motif_sans_mot_de_passe(): void
    {
        User::factory()->withRole(Role::Apprenant)->deactivated()->create(['email' => 'desactive@example.test']);

        // Identifiant inconnu : rien n'est journalisé (décision du 16/11/2026, pas de remplissage).
        $this->login('inconnu@example.test', 'mot-de-passe-fictif')->assertStatus(422);
        $this->assertNull($this->lastOf(AuditAction::LoginFailed));

        // Compte existant, mauvais mot de passe : journalisé avec l'identifiant et le motif.
        $this->login('desactive@example.test', 'mot-de-passe-fictif')->assertStatus(422);
        $wrong = $this->lastOf(AuditAction::LoginFailed);
        $this->assertEquals(['identifiant' => 'desactive@example.test', 'motif' => 'invalid_credentials'], $wrong?->new_values);
        $this->assertNotNull($wrong->subject_id);
        $this->assertNull($wrong->user_id);

        $this->login('desactive@example.test')->assertForbidden();
        $deactivated = $this->lastOf(AuditAction::LoginFailed);
        $this->assertSame('account_deactivated', $deactivated?->new_values['motif'] ?? null);
        $this->assertNotNull($deactivated->subject_id);

        $this->assertStringNotContainsString('mot-de-passe-fictif', (string) json_encode(AuditLog::query()->get()));
    }

    public function test_un_blocage_est_journalise_une_seule_fois(): void
    {
        User::factory()->withRole(Role::Entreprise)->create(['username' => 'societe-fictive']);

        for ($i = 0; $i < 5; $i++) {
            $this->login('societe-fictive', 'mauvais');
        }
        $this->login('societe-fictive', 'mauvais')->assertStatus(429);
        $this->login('societe-fictive', 'mauvais')->assertStatus(429);
        $this->login('société-fictive', 'mauvais')->assertStatus(429);

        $throttled = AuditLog::query()->where('action', AuditAction::LoginThrottled->value)->get();
        $this->assertCount(1, $throttled);
        $this->assertSame('societe-fictive', $throttled[0]->new_values['identifiant'] ?? null);
        $this->assertSame('compte', $throttled[0]->new_values['limite'] ?? null);
    }

    /**
     * Régression (revue Codex T-06, constat 3) : le blocage global par IP (100/min) n'est
     * journalisé qu'une fois, même si chaque tentative vise un identifiant différent.
     */
    public function test_le_blocage_global_par_ip_est_journalise_une_seule_fois(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->login("inconnu-{$i}@example.test", 'mauvais', '10.0.0.7')->assertStatus(422);
        }
        for ($i = 100; $i < 105; $i++) {
            $this->login("inconnu-{$i}@example.test", 'mauvais', '10.0.0.7')->assertStatus(429);
        }

        $throttled = AuditLog::query()->where('action', AuditAction::LoginThrottled->value)->get();
        $this->assertCount(1, $throttled);
        $this->assertSame('adresse_ip', $throttled[0]->new_values['limite'] ?? null);
        // Identifiants inconnus : aucun échec individuel journalisé.
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::LoginFailed->value)->count());
    }
}
