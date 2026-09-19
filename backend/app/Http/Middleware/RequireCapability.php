<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireCapability — gate on a specific capability string.
 *
 * Usage:
 *   Route::middleware('can.do:admin.users.manage')->group(...)
 *   Route::middleware('can.do:platform.flags.manage')->group(...)
 */
class RequireCapability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if (! $user->can_do($capability)) {
            return response()->json([
                'error' => 'Forbidden',
                'reason' => 'missing_capability',
                'required_capability' => $capability,
            ], 403);
        }

        return $next($request);
    }
}
