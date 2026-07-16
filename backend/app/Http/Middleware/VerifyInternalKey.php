<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates backend-internal routes called by the Python intelligence workers
 * (never by the cockpit or any external client). Workers authenticate with
 * a shared key over the private Docker network — see BACKEND_INTERNAL_KEY
 * and how intelligence/agents/*.py call {BACKEND_URL}/api/internal/*.
 */
class VerifyInternalKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.internal.key', env('BACKEND_INTERNAL_KEY', ''));

        if ($expected === '' || ! hash_equals($expected, (string) $request->header('X-Internal-Key', ''))) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
