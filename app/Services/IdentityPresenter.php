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
            'uuid' => $account->uuid,
            'email' => $account->email,
            'full_name' => $account->full_name,
            'status' => $account->status,
            'roles' => $account->getRoleNames()->values(),
            // Sprint 3: surface the granular abilities the UI gates on (edit
            // affordances are hidden/disabled without knowledge.edit).
            'abilities' => [
                'knowledge.edit' => $account->can('knowledge.edit'),
            ],
        ];
    }
}
