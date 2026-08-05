<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional cross-origin tightening for GET /api/public/traces|approvals.
 *
 * When PUBLIC_SHARE_ALLOWED_ORIGINS is non-empty, requests that send an
 * `Origin` header must match one of the listed origins (exact string).
 * Origins are comma-separated in .env. When empty, this middleware is a no-op
 * and global CORS (config/cors.php) applies unchanged.
 */
class PublicShareCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = $this->parseOrigins((string) config('spidernet.public_share_allowed_origins', ''));

        if ($allowed === []) {
            return $next($request);
        }

        $origin = $request->headers->get('Origin');

        if ($request->getMethod() === 'OPTIONS') {
            if ($origin && ! in_array($origin, $allowed, true)) {
                return response('', 403);
            }
            if ($origin && in_array($origin, $allowed, true)) {
                return response('', 204)
                    ->header('Access-Control-Allow-Origin', $origin)
                    ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Accept, Content-Type')
                    ->header('Access-Control-Allow-Credentials', 'false')
                    ->header('Access-Control-Max-Age', '86400')
                    ->header('Vary', 'Origin');
            }

            return response('', 204);
        }

        /** @var Response $response */
        $response = $next($request);

        if ($origin && ! in_array($origin, $allowed, true)) {
            return response()->json(['message' => 'Origin not allowed for public share endpoints.'], 403);
        }

        if ($origin && in_array($origin, $allowed, true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Accept, Content-Type');
            $response->headers->set('Access-Control-Allow-Credentials', 'false');
            $response->headers->set('Access-Control-Max-Age', '86400');
            $response->headers->set('Vary', 'Origin', false);
        }

        return $response;
    }

    /**
     * @return list<string>
     */
    private function parseOrigins(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
