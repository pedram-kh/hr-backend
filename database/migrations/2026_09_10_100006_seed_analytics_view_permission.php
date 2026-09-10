<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sprint 8 (additive, data; plan.md §9, §11 item 7, §12 resolved q7) — seed
 * the `analytics.view` ability so a prod `migrate` and a fresh install both
 * get it (RoleSeeder is updated in lockstep for fresh seeds; this migration
 * covers already-migrated databases). Idempotent: findOrCreate +
 * givePermissionTo are safe to re-run.
 *
 *  - analytics.view : see Analítica/Cobertura (aggregates only — no
 *    `chat_session_id` ever reaches these screens' own queries) — granted to
 *    super_admin, hr_agent, auditor (the three roles the spec's access
 *    matrix names). knowledge_editor gets the Cobertura (coverage) VIEW only
 *    via its existing `knowledge.edit` ability (coverage is a knowledge-gap
 *    view, not a new grant) — NOT via analytics.view, and NOT Analítica/
 *    Calidad, which stay analytics.view/escalation.work-gated respectively.
 *    This is the ONE place Sprint 8 invents a new access-control primitive
 *    rather than purely reusing an existing one (flagged explicitly in
 *    plan.md §12 q7 for review sign-off).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $analyticsView = Permission::findOrCreate('analytics.view', 'web');

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $hrAgent = Role::findOrCreate('hr_agent', 'web');
        $auditor = Role::findOrCreate('auditor', 'web');

        $superAdmin->givePermissionTo($analyticsView);
        $hrAgent->givePermissionTo($analyticsView);
        $auditor->givePermissionTo($analyticsView);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::where('name', 'analytics.view')->where('guard_name', 'web')->first()?->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
