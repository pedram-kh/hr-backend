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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
