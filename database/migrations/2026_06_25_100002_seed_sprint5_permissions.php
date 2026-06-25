<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sprint 5 (additive, data) — seed the three Sprint-5 abilities so a prod
 * `migrate` and a fresh install both get them (RoleSeeder is updated in lockstep
 * for fresh seeds; this migration covers already-migrated databases). Idempotent:
 * findOrCreate + givePermissionTo are safe to re-run.
 *
 *  - history.view_all  : the gated full-history browser/search (ADR-0018) —
 *                        super_admin + auditor ONLY. Distinct from escalation.work.
 *  - directory.manage  : employee directory CRUD/CSV + directory reads —
 *                        super_admin + hr_agent (agents do day-to-day corrections,
 *                        ADR-0004; directory PII is a lower tier than chat content).
 *  - admin.manage      : admin accounts + role assignment — super_admin ONLY (the
 *                        most privileged action: it grants history.view_all).
 *
 * Roles are findOrCreate'd here too so the grant works regardless of migration vs
 * seeder ordering (lockstep with RoleSeeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = [];
        foreach (['super_admin', 'hr_agent', 'knowledge_editor', 'auditor'] as $role) {
            $roles[$role] = Role::findOrCreate($role, 'web');
        }

        $historyViewAll = Permission::findOrCreate('history.view_all', 'web');
        $directoryManage = Permission::findOrCreate('directory.manage', 'web');
        $adminManage = Permission::findOrCreate('admin.manage', 'web');

        $roles['super_admin']->givePermissionTo($historyViewAll, $directoryManage, $adminManage);
        $roles['auditor']->givePermissionTo($historyViewAll);
        $roles['hr_agent']->givePermissionTo($directoryManage);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['history.view_all', 'directory.manage', 'admin.manage'] as $name) {
            $permission = Permission::where('name', $name)->where('guard_name', 'web')->first();
            $permission?->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
