<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Http\Controllers\Auth\LoginController;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * AUTH-01 : connexion par e-mail ou nom d'utilisateur.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function login(string $identifier, string $password = 'password'): TestResponse
    {
        // Origine déclarée : Sanctum traite la requête comme venant de l'application (session par cookie).
        return $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/v1/login', ['identifier' => $identifier, 'password' => $password]);
    }

    public function test_connexion_par_e_mail(): void
    {
        $user = User::factory()->withRole(Role::Cme)->create(['email' => 'cme@example.test']);

        $this->login('cme@example.test')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', 'cme')
            ->assertJsonPath('data.home', '/pilotage');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotNull($user->fresh()?->last_login_at);
    }

    public function test_connexion_d_une_entreprise_par_nom_d_utilisateur(): void
    {
        $user = User::factory()->withRole(Role::Entreprise)->create(['username' => 'societe-fictive']);

        $this->login('societe-fictive')
            ->assertOk()
            ->assertJsonPath('data.role', 'entreprise')
            ->assertJsonPath('data.home', '/entreprise')
            ->assertJsonPath('data.email', null);

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_mot_de_passe_faux_message_generique(): void
    {
        User::factory()->withRole(Role::Apprenant)->create(['email' => 'apprenant@example.test']);

        $this->login('apprenant@example.test', 'mauvais-mot-de-passe')
            ->assertStatus(422)
            ->assertExactJson(['message' => LoginController::INVALID_CREDENTIALS, 'code' => 'invalid_credentials']);

        $this->assertGuest('web');
    }

    public function test_identifiant_inconnu_meme_message_generique(): void
    {
        $this->login('inconnu@example.test')
            ->assertStatus(422)
            ->assertExactJson(['message' => LoginController::INVALID_CREDENTIALS, 'code' => 'invalid_credentials']);
    }

    /**
     * Régression (revue Codex T-04, constat 7) : pour un identifiant inconnu, un mot de passe
     * est quand même vérifié (hash factice), pour ne pas révéler l'existence du compte par la durée.
     */
    public function test_identifiant_inconnu_verifie_quand_meme_un_mot_de_passe(): void
    {
        $dummy = Hash::make('secret-factice');
        Cache::forever('auth:login:dummy-hash:'.config('hashing.bcrypt.rounds'), $dummy);

        Hash::partialMock()
            ->shouldReceive('check')
            ->once()
            ->with('mot-de-passe-saisi', $dummy)
            ->andReturnFalse();

        $this->login('inconnu@example.test', 'mot-de-passe-saisi')
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_compte_desactive_refuse(): void
    {
        User::factory()->withRole(Role::Apprenant)->deactivated()->create(['email' => 'desactive@example.test']);

        $this->login('desactive@example.test')
            ->assertForbidden()
            ->assertJsonPath('message', 'Votre compte est désactivé. Contactez l\'administration.')
            ->assertJsonPath('code', 'account_deactivated');

        $this->assertGuest('web');
    }

    public function test_compte_desactive_avec_mot_de_passe_faux_reste_generique(): void
    {
        User::factory()->withRole(Role::Apprenant)->deactivated()->create(['email' => 'desactive@example.test']);

        $this->login('desactive@example.test', 'mauvais-mot-de-passe')
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_compte_non_active_refuse(): void
    {
        User::factory()->withRole(Role::Cme)->unactivated()->create(['email' => 'nouveau@example.test']);

        $this->login('nouveau@example.test')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_not_activated');

        $this->assertGuest('web');
    }

    public function test_compte_supprime_traite_comme_inexistant(): void
    {
        $user = User::factory()->withRole(Role::Cme)->create(['email' => 'supprime@example.test']);
        $user->delete();

        $this->login('supprime@example.test')
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_compte_sans_role_refuse(): void
    {
        User::factory()->create(['email' => 'sans-role@example.test']);

        $this->login('sans-role@example.test')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_without_role');

        $this->assertGuest('web');
    }

    public function test_champs_obligatoires(): void
    {
        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/v1/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['identifier', 'password']);
    }
}
