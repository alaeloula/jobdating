<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Régression (revue Codex T-04, constat 3) : l'annulation de la migration users
 * ne doit jamais laisser la table à moitié revenue en arrière.
 */
class UsersMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_annulation_refusee_avant_toute_modification_s_il_existe_un_compte_sans_e_mail(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->withRole(Role::Entreprise)->create(['username' => 'societe-fictive']);

        $migration = require database_path('migrations/2026_11_14_090000_update_users_table_for_authentication.php');

        try {
            $migration->down();
            $this->fail('L\'annulation aurait dû être refusée.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 compte(s) sans e-mail', $e->getMessage());
        }

        // Structure T-04 intacte : aucune colonne supprimée ni recréée.
        foreach (['first_name', 'last_name', 'username', 'phone', 'activated_at', 'last_login_at', 'deactivated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('users', $column), "Colonne {$column} attendue.");
        }
        $this->assertFalse(Schema::hasColumn('users', 'name'));
        $this->assertSame('societe-fictive', User::query()->value('username'));
    }
}
