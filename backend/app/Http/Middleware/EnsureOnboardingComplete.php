<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureOnboardingComplete
{
    /**
     * Routes exempt from onboarding check (by name or pattern)
     */
    private const EXEMPT_ROUTES = [
        'auth.*',
        'admin.onboarding.*',
        'atlas.chat',
        'events.*',
    ];

    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // No user = not authenticated; let auth middleware handle it
        if (! $user) {
            return $next($request);
        }

        // Check route exemption using routeIs()
        foreach (self::EXEMPT_ROUTES as $pattern) {
            if ($request->routeIs($pattern)) {
                return $next($request);
            }
        }

        // Onboarding completed check
        if (! $user->onboarding_completed_at) {
            return response()->json([
                'redirect' => '/onboarding',
                'message' => 'Onboarding must be completed before accessing this resource',
            ], 403);
        }

        return $next($request);
    }
}
