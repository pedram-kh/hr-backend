<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Employee;

/**
 * Builds the identity payload returned by /me and verify-code.
 *
 * For employees it includes the RAW profile facets (convenio, province,
 * job_category, employment_type). It does NOT compute eligibility/scope —
 * that deterministic logic belongs to a later sprint (Sprint 0 review Q6).
 */
class IdentityPresenter
{
    public static function present(Employee|Admin $account, string $accountType): array
    {
        if ($account instanceof Employee) {
            $account->loadMissing(['convenio', 'territory', 'jobCategory']);

            return [
                'account_type' => 'employee',
                'uuid' => $account->uuid,
                'email' => $account->email,
                'full_name' => $account->full_name,
                'status' => $account->status,
                'profile' => [
                    'employment_type' => $account->employment_type,
                    'work_location' => $account->work_location,
                    'convenio' => $account->convenio ? [
                        'numero' => $account->convenio->numero,
                        'name' => $account->convenio->name,
                    ] : null,
                    'territory' => $account->territory ? [
                        'code' => $account->territory->code,
                        'name' => $account->territory->name,
                        'level' => $account->territory->level,
                    ] : null,
                    'job_category' => $account->jobCategory ? [
                        'name' => $account->jobCategory->name,
                        'group_code' => $account->jobCategory->group_code,
                    ] : null,
                ],
            ];
        }

        return [
            'account_type' => 'admin',
            // The numeric id is surfaced so the escalation board can self-assign
            // ("assign to me") without a directory (the directory is Sprint 5).
            'id' => $account->id,
            'uuid' => $account->uuid,
            'email' => $account->email,
            'full_name' => $account->full_name,
            'status' => $account->status,
            'roles' => $account->getRoleNames()->values(),
            // Sprint 3/4/5: surface the granular abilities the UI gates on. The
            // UI only HIDES on these — the server enforces them on every endpoint
            // (ADR-0018). history.view_all gates the full-history browser/search;
            // directory.manage the employee directory; admin.manage admin/role mgmt.
            'abilities' => [
                'knowledge.edit' => $account->can('knowledge.edit'),
                'escalation.work' => $account->can('escalation.work'),
                'history.view_all' => $account->can('history.view_all'),
                'directory.manage' => $account->can('directory.manage'),
                'admin.manage' => $account->can('admin.manage'),
                'guardrails.manage' => $account->can('guardrails.manage'),
                // Sprint 7a (ADR-0011/0020): approve a vocabulary proposal into the
                // controlled vocabulary — super_admin only (the most-guarded write).
                'vocabulary.approve' => $account->can('vocabulary.approve'),
                // Sprint 8 (ADR-0030). BUG found live, eyes-on 2026-09-10: this key
                // was never added when analytics.view was introduced, so
                // `canViewAnalytics`/`canViewCoverage` on the frontend always read
                // `undefined` here regardless of the real Spatie grant —
                // Analítica's nav entry was unreachable for EVERY role, and
                // Cobertura's nav entry silently survived only for roles that
                // also happen to hold `knowledge.edit` (super_admin), not for
                // hr_agent/auditor, who hold analytics.view but not knowledge.edit.
                'analytics.view' => $account->can('analytics.view'),
            ],
        ];
    }
}
