<?php

namespace Database\Seeders;

use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Permissions
        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // 2. Roles + grants
        foreach (Roles::definitions() as $roleName => $grants) {
            $role = Role::findOrCreate($roleName, 'web');

            if ($grants === ['*']) {
                $role->syncPermissions(Permissions::all());
            } else {
                $role->syncPermissions($grants);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
