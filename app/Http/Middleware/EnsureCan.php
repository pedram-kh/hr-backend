<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route on a spatie permission. Used as `ability:knowledge.edit` on the
 * Knowledge-Center WRITE routes (Sprint 3) and `ability:escalation.work` on the
 * escalation-board WRITE routes (Sprint 4) — reads stay open to any admin.
 *
 *  - `knowledge.edit`  : super_admin + knowledge_editor (deny auditor + hr_agent)
 *  - `escalation.work` : super_admin + hr_agent       (deny auditor + knowledge_editor)
 *
 * spatie registers permissions with the Gate, so `$admin->can($ability)`
 * reflects the role grant (RoleSeeder).
 */
class EnsureCan
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();
        if ($user === null || ! method_exists($user, 'can') || ! $user->can($ability)) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }
}
