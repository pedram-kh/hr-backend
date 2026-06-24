<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route on a spatie permission (Sprint 3). Used as `ability:knowledge.edit`
 * on the Knowledge-Center WRITE routes only — reads stay open to any admin.
 *
 * The `knowledge.edit` ability is granted to super_admin + knowledge_editor and
 * denied to auditor + hr_agent (RoleSeeder). spatie registers permissions with
 * the Gate, so `$admin->can('knowledge.edit')` reflects the role grant.
 */
class EnsureCan
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();
        if ($user === null || ! method_exists($user, 'can') || ! $user->can($ability)) {
            return response()->json([
                'message' => 'You do not have permission to edit knowledge labels.',
            ], 403);
        }

        return $next($request);
    }
}
