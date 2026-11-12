<?php

namespace Tests\Feature\Authorization;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

/**
 * Un seul rôle par compte (RG-ACC-06) et seeder des droits fiable.
 */
class SingleRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_un_compte_sans_role_n_a_aucun_role(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->currentRole());
    }

    public function test_set_role_remplace_le_role_precedent(): void
    {
        $user = User::factory()->create();

        $user->setRole(Role::Cme);
        $user->setRole(Role::Direction);

        $user->refresh();
        $this->assertSame(Role::Direction, $user->currentRole());
        $this->assertCount(1, $user->roles);
        $this->assertFalse($user->hasPermissionTo(Permission::ReferentialsManage->value));
    }

    public function test_ajouter_un_second_role_est_refuse(): void
    {
        $user = User::factory()->create();
        $user->setRole(Role::Entreprise);

        $this->expectException(LogicException::class);

        $user->assignRole(Role::Administrateur->value);
    }

    public function test_attribuer_deux_roles_en_une_fois_est_refuse(): void
    {
        $user = User::factory()->create();

        $this->expectException(LogicException::class);

        $user->assignRole(Role::Cme->value, Role::Direction->value);
    }

    public function test_reattribuer_le_meme_role_est_accepte(): void
    {
        $user = User::factory()->create();
        $user->setRole(Role::Apprenant);

        $user->assignRole(Role::Apprenant->value);

        $this->assertCount(1, $user->refresh()->roles);
    }

    public function test_le_seeder_peut_etre_relance_sans_doublon(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(count(Role::cases()), RoleModel::query()->count());
        $this->assertSame(count(Permission::cases()), PermissionModel::query()->count());

        foreach (Role::cases() as $role) {
            $this->assertCount(
                count($role->permissions()),
                RoleModel::findByName($role->value, 'web')->permissions,
                "Rôle « {$role->value} »",
            );
        }
    }

    public function test_le_seeder_retire_une_permission_qui_n_existe_plus_dans_le_code(): void
    {
        PermissionModel::findOrCreate('obsolete.permission', 'web');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertFalse(PermissionModel::query()->where('name', 'obsolete.permission')->exists());
    }

    public function test_les_donnees_initiales_ne_creent_aucun_compte(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::query()->count());
    }
}
