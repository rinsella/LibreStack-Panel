<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Permissions
        $permissionIds = [];
        foreach (Permissions::all() as $name => $label) {
            $permission = Permission::updateOrCreate(['name' => $name], ['label' => $label]);
            $permissionIds[$name] = $permission->id;
        }

        // Roles + their permissions
        foreach (Permissions::roles() as $name => $config) {
            $role = Role::updateOrCreate(
                ['name' => $name],
                ['label' => $config['label'], 'description' => $config['description']]
            );

            $ids = array_values(array_filter(array_map(
                fn (string $perm) => $permissionIds[$perm] ?? null,
                $config['permissions']
            )));

            $role->permissions()->sync($ids);
        }
    }
}
