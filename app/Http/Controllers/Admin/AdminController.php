<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin account + role management (Sprint 5). Replaces the env-seeded test users
 * as the day-to-day path (the SEED_*_EMAIL fixtures stay as a bootstrap
 * fallback). Roles are the four conceptual roles, assigned via
 * spatie/laravel-permission (syncRoles) — assigning a role grants its abilities
 * (knowledge.edit / escalation.work / history.view_all / directory.manage /
 * admin.manage). Deactivation removes access immediately (token revocation +
 * the EnsureActiveAccount gate).
 *
 * Behind `ability:admin.manage` (super_admin ONLY): creating an admin and
 * granting history.view_all is the most privileged action in the system (ADR-0018).
 */
class AdminController extends Controller
{
    /** The four conceptual roles (architecture §11); the only assignable roles. */
    public const ROLES = ['super_admin', 'hr_agent', 'knowledge_editor', 'auditor'];

    public function index(): JsonResponse
    {
        $admins = Admin::with('roles:id,name')->orderBy('full_name')->get()
            ->map(fn (Admin $a) => $this->present($a));

        return response()->json([
            'admins' => $admins,
            'roles' => self::ROLES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', Rule::unique('admins', 'email')],
            'full_name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'roles' => ['array'],
            'roles.*' => [Rule::in(self::ROLES)],
        ]);

        $admin = DB::transaction(function () use ($data) {
            $admin = Admin::create([
                'email' => strtolower(trim($data['email'])),
                'full_name' => $data['full_name'],
                'status' => $data['status'] ?? 'active',
            ]);
            $admin->syncRoles($data['roles'] ?? []);

            return $admin;
        });

        return response()->json(['admin' => $this->present($admin->fresh('roles'))], 201);
    }

    public function update(string $uuid, Request $request): JsonResponse
    {
        $admin = Admin::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        DB::transaction(function () use ($admin, $data) {
            $deactivating = isset($data['status']) && $data['status'] === 'inactive' && $admin->status !== 'inactive';
            $admin->fill($data);
            $admin->save();
            // Deactivation removes access immediately: kill outstanding tokens so a
            // live ~24h session dies now, not in up-to-24h (ADR-0018).
            if ($deactivating) {
                $admin->tokens()->delete();
            }
        });

        return response()->json(['admin' => $this->present($admin->fresh('roles'))]);
    }

    /** Assign the four roles through the UI (spatie syncRoles). */
    public function syncRoles(string $uuid, Request $request): JsonResponse
    {
        $admin = Admin::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => [Rule::in(self::ROLES)],
        ]);

        $admin->syncRoles($data['roles']);

        return response()->json(['admin' => $this->present($admin->fresh('roles'))]);
    }

    /** @return array<string, mixed> */
    private function present(Admin $admin): array
    {
        return [
            'uuid' => $admin->uuid,
            'email' => $admin->email,
            'full_name' => $admin->full_name,
            'status' => $admin->status,
            'roles' => $admin->getRoleNames()->values(),
            'abilities' => [
                'knowledge.edit' => $admin->can('knowledge.edit'),
                'escalation.work' => $admin->can('escalation.work'),
                'history.view_all' => $admin->can('history.view_all'),
                'directory.manage' => $admin->can('directory.manage'),
                'admin.manage' => $admin->can('admin.manage'),
            ],
        ];
    }
}
