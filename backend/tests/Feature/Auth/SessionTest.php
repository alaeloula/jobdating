<?php

namespace Tests\Feature\Auth;

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Middleware\EnsureSessionIsValid;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * /me, déconnexion (RG-AUTH-04), durée de session (RG-AUTH-02)
 * et perte de session d'un compte désactivé ou supprimé (RG-ACC-05).
 */
class SessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withHeader('Origin', 'http://localhost');
    }

    public function test_me_exige_une_session(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Votre session a expiré. Veuillez vous reconnecter.',
                'code' => 'unauthenticated',
            ]);
    }

    /**
     * @return iterable<string, array{Role, string}>
     */
    public static function homes(): iterable
    {
        yield 'administrateur' => [Role::Administrateur, '/pilotage'];
        yield 'cme' => [Role::Cme, '/pilotage'];
        yield 'direction' => [Role::Direction, '/pilotage'];
        yield 'entreprise' => [Role::Entreprise, '/entreprise'];
        yield 'apprenant' => [Role::Apprenant, '/ma-journee'];
    }

    #[DataProvider('homes')]
    public function test_me_donne_role_permissions_et_accueil(Role $role, string $home): void
    {
        $user = User::factory()->withRole($role)->create();

        $expected = array_map(fn (Permission $p) => $p->value, $role->permissions());
        sort($expected);

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.first_name', $user->first_name)
            ->assertJsonPath('data.last_name', $user->last_name)
            ->assertJsonPath('data.role', $role->value)
            ->assertJsonPath('data.role_label', $role->label())
            ->assertJsonPath('data.home', $home)
            ->assertJsonPath('data.permissions', $expected)
            ->assertJsonMissingPath('data.password');
    }

    public function test_deconnexion_ferme_la_session(): void
    {
        $user = User::factory()->withRole(Role::Cme)->create();

        $this->actingAs($user, 'web')->postJson('/api/v1/logout')->assertNoContent();

        $this->assertGuest('web');
        $this->assertFalse(session()->has('login_web_'.sha1(SessionGuard::class)));

        // Nouvelle requête : les gardes en mémoire du test sont réinitialisés, comme en production.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_compte_desactive_perd_sa_session(): void
    {
        $user = User::factory()->withRole(Role::Apprenant)->create();

        $this->actingAs($user, 'web')->getJson('/api/v1/me')->assertOk();

        $user->forceFill(['deactivated_at' => now()])->save();

        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_compte_supprime_perd_sa_session(): void
    {
        $user = User::factory()->withRole(Role::Apprenant)->create();
        $user->delete();

        // Le garde ne retrouve plus le compte à partir de la session.
        $this->withSession(['login_web_'.sha1(SessionGuard::class) => $user->id])
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_session_expiree_apres_douze_heures(): void
    {
        $user = User::factory()->withRole(Role::Entreprise)->create();

        $this->actingAs($user, 'web')
            ->withSession([EnsureSessionIsValid::LOGGED_IN_AT => now()->subHours(12)->getTimestamp()])
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        $this->assertGuest('web');
    }

    public function test_session_valable_avant_douze_heures(): void
    {
        $user = User::factory()->withRole(Role::Entreprise)->create();

        $this->actingAs($user, 'web')
            ->withSession([EnsureSessionIsValid::LOGGED_IN_AT => now()->subHours(11)->getTimestamp()])
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    /**
     * Question 2 de la revue Codex T-04 : une session sans heure de connexion
     * (non ouverte par POST /login) est refusée.
     */
    public function test_session_sans_heure_de_connexion_refusee(): void
    {
        $user = User::factory()->withRole(Role::Cme)->create();

        // be() ouvre la session sans l'heure de connexion (contrairement à TestCase::actingAs()).
        $this->be($user, 'web');

        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertGuest('web');
    }

    /**
     * Cycle complet par cookie, sans actingAs : connexion, /me, déconnexion, /me refusé.
     */
    public function test_cycle_connexion_me_deconnexion(): void
    {
        User::factory()->withRole(Role::Entreprise)->create(['username' => 'societe-fictive']);

        $this->postJson('/api/v1/login', ['identifier' => 'societe-fictive', 'password' => 'password'])->assertOk();
        $this->assertIsInt(session(EnsureSessionIsValid::LOGGED_IN_AT));

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.username', 'societe-fictive');

        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    /**
     * Régression (revue Codex T-04, constat 6) : hors tests, la protection CSRF est active
     * et l'erreur 419 est renvoyée en JSON, en français.
     */
    public function test_jeton_csrf_absent_erreur_419_en_francais(): void
    {
        // Hors environnement « testing », Laravel applique la vérification CSRF.
        $this->app->detectEnvironment(fn () => 'local');

        $this->postJson('/api/v1/login', ['identifier' => 'societe-fictive', 'password' => 'password'])
            ->assertStatus(419)
            ->assertExactJson([
                'message' => 'La page a expiré. Veuillez réessayer.',
                'code' => 'csrf_token_mismatch',
            ]);
    }
}
