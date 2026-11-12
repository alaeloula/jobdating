<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crée les rôles et permissions de la matrice des droits (CDC 3.2).
 *
 * Idempotent et utilisable en production : peut être relancé sans créer de doublons,
 * et aligne la base sur le code (permissions retirées du code supprimées).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    private const GUARD = 'web';

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $names = array_map(fn (Permission $permission) => $permission->value, Permission::cases());

        foreach ($names as $name) {
            PermissionModel::findOrCreate($name, self::GUARD);
        }

        PermissionModel::query()
            ->where('guard_name', self::GUARD)
            ->whereNotIn('name', $names)
            ->delete();

        foreach (Role::cases() as $role) {
            RoleModel::findOrCreate($role->value, self::GUARD)->syncPermissions(
                array_map(fn (Permission $permission) => $permission->value, $role->permissions()),
            );
        }

        $registrar->forgetCachedPermissions();
    }
}
