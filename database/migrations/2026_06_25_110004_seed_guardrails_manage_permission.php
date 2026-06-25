<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sprint 6 (additive, data; ADR-0019) — seed the `guardrails.manage` ability so a
 * prod `migrate` and a fresh install both get it (RoleSeeder is updated in
 * lockstep for fresh seeds; this migration covers already-migrated databases).
 * Idempotent: findOrCreate + givePermissionTo are safe to re-run.
 *
 *  - guardrails.manage : WRITE the admin guardrail layer (thresholds, blocked
 *    topics, off-domain, tone, convert-by-reason) — super_admin ONLY. This is the
 *    most safety-sensitive surface; it sits at the top with admin.manage. READS
 *    are open to any admin (auditor included — read-only oversight). hr_agent /
 *    knowledge_editor get NEITHER write nor a special read beyond the open read.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $guardrailsManage = Permission::findOrCreate('guardrails.manage', 'web');

        $superAdmin->givePermissionTo($guardrailsManage);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::where('name', 'guardrails.manage')->where('guard_name', 'web')->first()?->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
