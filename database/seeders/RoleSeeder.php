<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The four admin roles (architecture §11). Sprint 3 adds the first granular
 * permission, `knowledge.edit`, gating the Knowledge-Center WRITE routes:
 * granted to super_admin + knowledge_editor; denied to auditor (read-only) and
 * hr_agent. Full role/permission management is Sprint 5.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = [];
        foreach (['super_admin', 'hr_agent', 'knowledge_editor', 'auditor'] as $role) {
            $roles[$role] = Role::findOrCreate($role, 'web');
        }

        // Sprint-3 ability: edit document labels (bounded edit, spec D).
        $knowledgeEdit = Permission::findOrCreate('knowledge.edit', 'web');

        // Grant to the label editors; auditor + hr_agent are NOT granted (auditor
        // browses + inspects + runs the read-only sandbox, but cannot edit).
        $roles['super_admin']->givePermissionTo($knowledgeEdit);
        $roles['knowledge_editor']->givePermissionTo($knowledgeEdit);

        // Sprint-4 ability: work the escalation board — assign/move cards, reply
        // to the employee, resolve, and publish a ruling (spec A–D). Granted to
        // super_admin + hr_agent; DENIED to knowledge_editor (no chat access) and
        // auditor (read-only — auditor may still BROWSE the board, which is a read,
        // but cannot act). Reads stay open to any admin (mirrors Sprint 3).
        $escalationWork = Permission::findOrCreate('escalation.work', 'web');
        $roles['super_admin']->givePermissionTo($escalationWork);
        $roles['hr_agent']->givePermissionTo($escalationWork);

        // Sprint-5 abilities (ADR-0018 + resolved open questions). Kept in lockstep
        // with 2026_06_25_100002_seed_sprint5_permissions (the data migration that
        // lands these on already-migrated databases); both are idempotent.
        //  - history.view_all : the gated full-history browser/search — super_admin
        //    + auditor ONLY (a deliberately-granted oversight ability, distinct from
        //    escalation.work; an hr_agent stays card-scoped, knowledge_editor none).
        //  - directory.manage : employee directory CRUD/CSV + reads — super_admin +
        //    hr_agent (agents do day-to-day "transferred province" corrections, ADR-0004).
        //  - admin.manage     : admin accounts + role assignment — super_admin ONLY.
        $historyViewAll = Permission::findOrCreate('history.view_all', 'web');
        $directoryManage = Permission::findOrCreate('directory.manage', 'web');
        $adminManage = Permission::findOrCreate('admin.manage', 'web');

        $roles['super_admin']->givePermissionTo($historyViewAll, $directoryManage, $adminManage);
        $roles['auditor']->givePermissionTo($historyViewAll);
        $roles['hr_agent']->givePermissionTo($directoryManage);

        // Sprint-6 ability (ADR-0019). Kept in lockstep with
        // 2026_06_25_110004_seed_guardrails_manage_permission (the data migration
        // that lands it on already-migrated databases); both are idempotent.
        //  - guardrails.manage : WRITE the admin guardrail layer (thresholds,
        //    blocked topics, off-domain, tone, convert-by-reason) — super_admin
        //    ONLY (the most safety-sensitive surface, beside admin.manage). READS
        //    are open to any admin; auditor reads read-only; hr_agent / KE get no
        //    write. The hardcoded GuardrailService baseline is never editable.
        $guardrailsManage = Permission::findOrCreate('guardrails.manage', 'web');
        $roles['super_admin']->givePermissionTo($guardrailsManage);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
