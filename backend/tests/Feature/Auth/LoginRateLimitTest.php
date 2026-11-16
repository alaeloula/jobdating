<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * RG-AUTH-01 : 5 tentatives par minute pour un identifiant et une IP,
 * 100 par minute pour une IP seule ; au-delà HTTP 429 avec le délai.
 */
class LoginRateLimitTest extends TestCase
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
    private function attempt(string $identifier, string $password, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->withHeader('Origin', 'http://localhost')
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/login', ['identifier' => $identifier, 'password' => $password]);
    }

    public function test_cinq_tentatives_par_identifiant_et_ip(): void
    {
        User::factory()->withRole(Role::Entreprise)->create(['username' => 'societe-fictive']);

        for ($i = 0; $i < 5; $i++) {
            $this->attempt('societe-fictive', 'mauvais-mot-de-passe')->assertStatus(422);
        }

        // Sixième tentative refusée, même avec le bon mot de passe et une autre casse.
        $this->attempt('SOCIETE-FICTIVE', 'password')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'too_many_attempts')
            ->assertJsonStructure(['message', 'code', 'retry_after']);

        $this->assertGuest('web');

        // Même identifiant depuis une autre IP : non bloqué.
        $this->attempt('societe-fictive', 'password', '10.0.0.2')->assertOk();
    }

    /**
     * Régression (revue Codex T-04, constat 1) : MySQL considère « société » et « societe »
     * comme identiques ; le limiteur doit donc les compter ensemble.
     */
    public function test_les_variantes_d_un_meme_compte_partagent_le_compteur(): void
    {
        User::factory()->withRole(Role::Entreprise)->create(['username' => 'societe-fictive']);

        foreach (['societe-fictive', 'Societe-Fictive', ' societe-fictive ', 'SOCIETE-FICTIVE', 'société-fictive'] as $variant) {
            $this->attempt($variant, 'mauvais-mot-de-passe')->assertStatus(422);
        }

        $this->attempt('société-fictive', 'password')->assertStatus(429);
        $this->attempt('sociète-fictive', 'password')->assertStatus(429);
        $this->assertGuest('web');
    }

    /**
     * Régression (revue Codex T-04, constat 5) : une valeur non textuelle est rejetée
     * par la validation (422), sans erreur serveur dans le limiteur.
     */
    public function test_identifiant_non_textuel_rejete_par_la_validation(): void
    {
        foreach ([['societe-fictive'], ['cle' => 'societe-fictive'], 123, true] as $identifier) {
            $this->withHeader('Origin', 'http://localhost')
                ->postJson('/api/v1/login', ['identifier' => $identifier, 'password' => 'incorrect'])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['identifier']);
        }
    }

    public function test_un_autre_identifiant_n_est_pas_bloque(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('societe-a', 'mauvais-mot-de-passe');
        }

        $this->attempt('societe-b', 'mauvais-mot-de-passe')->assertStatus(422);
    }

    public function test_cent_tentatives_par_ip(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->attempt("compte-{$i}", 'mauvais-mot-de-passe')->assertStatus(422);
        }

        $this->attempt('compte-nouveau', 'mauvais-mot-de-passe')
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_attempts');

        // Une autre IP (autre participant) reste autorisée.
        $this->attempt('compte-nouveau', 'mauvais-mot-de-passe', '10.0.0.9')->assertStatus(422);
    }

    public function test_le_delai_d_attente_est_indique(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('societe-fictive', 'mauvais-mot-de-passe');
        }

        $response = $this->attempt('societe-fictive', 'mauvais-mot-de-passe')->assertStatus(429);

        $seconds = $response->json('retry_after');
        $this->assertIsInt($seconds);
        $this->assertGreaterThan(0, $seconds);
        $this->assertLessThanOrEqual(60, $seconds);
        $this->assertStringContainsString("{$seconds} secondes", (string) $response->json('message'));
    }
}
