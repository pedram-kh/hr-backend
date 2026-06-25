<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sprint 7a (additive, data — ADR-0011/0020) — seed the `vocabulary.approve`
 * ability so a prod `migrate` and a fresh install both get it (RoleSeeder is
 * updated in lockstep for fresh seeds; this migration covers already-migrated
 * databases). Idempotent: findOrCreate + givePermissionTo are safe to re-run.
 *
 *  - vocabulary.approve : APPROVE a vocabulary proposal into the controlled
 *    vocabulary (fold into aliases or create a new value) — super_admin ONLY.
 *    The most-guarded action (ADR-0011: vocabulary creation is the most-guarded
 *    write). A super_admin may propose-and-approve in one action. PROPOSING a
 *    value is gated by the existing `knowledge.edit` (super_admin +
 *    knowledge_editor); the AI proposes with no ability (it never approves).
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $vocabularyApprove = Permission::findOrCreate('vocabulary.approve', 'web');
        $superAdmin->givePermissionTo($vocabularyApprove);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::where('name', 'vocabulary.approve')->where('guard_name', 'web')->first();
        $permission?->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
