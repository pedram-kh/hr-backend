<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The four admin roles (architecture §11). Roles only this sprint; granular
 * permissions are added in the sprints that need them.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['super_admin', 'hr_agent', 'knowledge_editor', 'auditor'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
