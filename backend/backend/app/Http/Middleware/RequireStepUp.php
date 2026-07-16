<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequireStepUp — gate a route on fresh MFA step-up.
 *
 * Returns HTTP 428 (Precondition Required) with a payload the UI's StepUpGuard
 * component consumes to present a re-auth modal.
 *
 * Usage:
 *   Route::middleware('step.up')->group(...)
 */
class RequireStepUp
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        if (!$user->hasFreshStepUp()) {
            return response()->json([
                'error' => 'step_up_required',
                'reason' => 'step_up_stale',
                'ttl_seconds' => \App\Models\User::STEP_UP_TTL_SECONDS,
                'step_up_url' => '/api/auth/step-up',
            ], 428);
        }

        return $next($request);
    }
}
