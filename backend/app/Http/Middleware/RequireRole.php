<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireRole — gate a route on a minimum role rank.
 *
 * Usage:
 *   Route::middleware('role:admin')->group(...)
 *   Route::middleware('role:super_admin')->group(...)
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string $minRole): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if (!$user->atLeastRole($minRole)) {
            return response()->json([
                'error' => 'Forbidden',
                'reason' => 'insufficient_role',
                'required_role' => $minRole,
                'your_role' => $user->role,
            ], 403);
        }

        return $next($request);
    }
}
