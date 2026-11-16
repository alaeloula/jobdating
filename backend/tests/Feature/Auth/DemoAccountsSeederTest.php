<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DemoAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comptes de démonstration : développement local seulement (RG-AUTH-05, EXI-SEC-11).
 */
class DemoAccountsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_aucun_compte_hors_environnement_local(): void
    {
        $this->assertFalse($this->app->environment('local'));

        $this->seed(DemoAccountsSeeder::class);

        $this->assertSame(0, User::withTrashed()->count());
    }

    public function test_un_compte_par_role_en_local_et_relancable(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->seed(DemoAccountsSeeder::class);
        $this->seed(DemoAccountsSeeder::class);

        $this->assertSame(5, User::query()->count());

        foreach (Role::cases() as $role) {
            $this->assertSame(1, User::role($role->value)->count(), "Un compte {$role->value} attendu.");
        }

        // Retour à l'environnement de test (sinon le contrôle CSRF s'applique, comme en local).
        $this->app->detectEnvironment(fn () => 'testing');

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/v1/login', ['identifier' => 'entreprise-demo', 'password' => DemoAccountsSeeder::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.role', 'entreprise');
    }
}
