<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprint 8 (plan.md §9, §12 resolved q7) — Cobertura is gated on
 * `analytics.view` OR `knowledge.edit`, the one OR-of-two-existing-abilities
 * gate this sprint needs (so `knowledge_editor` reaches coverage via its
 * EXISTING ability, without gaining `analytics.view` and therefore without
 * gaining Analítica or quality-sample review). A tiny dedicated middleware
 * rather than extending `EnsureCan` to parse an "a|b" ability string for a
 * single call site.
 */
class EnsureCanViewCoverage
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $allowed = $user !== null && method_exists($user, 'can')
            && ($user->can('analytics.view') || $user->can('knowledge.edit'));

        if (! $allowed) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }
}
