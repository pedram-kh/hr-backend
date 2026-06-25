<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivation removes access immediately (ADR-0018). An authenticated account
 * whose `status` is not `active` is refused on every request — so deactivating
 * an admin or an employee ends their access at once, even within a live ~24h
 * Sanctum session. Token revocation on deactivate (the controllers) closes the
 * window; this gate is the belt-and-braces server boundary regardless.
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ($user->status ?? 'active') !== 'active') {
            return response()->json(['message' => 'This account is inactive.'], 403);
        }

        return $next($request);
    }
}
