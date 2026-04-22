<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SecurityHeadersMiddleware
 *
 * Emits the Tier 1 security header set on every HTTP response:
 *   - Strict-Transport-Security (HTTPS only)
 *   - Content-Security-Policy / Content-Security-Policy-Report-Only
 *   - X-Frame-Options
 *   - X-Content-Type-Options
 *   - Referrer-Policy
 *   - Permissions-Policy
 *   - Cross-Origin-Opener-Policy
 *   - Cross-Origin-Resource-Policy
 *
 * All values are driven by config/security.php so that ops can tune
 * per-environment via env without a code change.
 */
class SecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Skip excluded paths (e.g. Laravel's /up health probe)
        $excluded = (array) config('security.excluded_paths', []);
        foreach ($excluded as $path) {
            if ($request->is($path)) {
                return $response;
            }
        }

        $headers = (array) config('security.headers', []);

        // ─── HSTS (HTTPS only) ──────────────────────────────────────────
        $hsts = $headers['hsts'] ?? [];
        if (($hsts['enabled'] ?? false) && $request->isSecure()) {
            $value = 'max-age=' . (int) ($hsts['max_age'] ?? 0);
            if (!empty($hsts['include_subdomains'])) {
                $value .= '; includeSubDomains';
            }
            if (!empty($hsts['preload'])) {
                $value .= '; preload';
            }
            $response->headers->set('Strict-Transport-Security', $value);
        }

        // ─── Static headers ─────────────────────────────────────────────
        $response->headers->set('X-Frame-Options', $headers['frame_options'] ?? 'DENY');
        $response->headers->set('X-Content-Type-Options', $headers['content_type'] ?? 'nosniff');
        $response->headers->set('Referrer-Policy', $headers['referrer_policy'] ?? 'strict-origin-when-cross-origin');

        if (!empty($headers['permissions_policy'])) {
            $response->headers->set('Permissions-Policy', $headers['permissions_policy']);
        }
        if (!empty($headers['cross_origin_opener_policy'])) {
            $response->headers->set('Cross-Origin-Opener-Policy', $headers['cross_origin_opener_policy']);
        }
        if (!empty($headers['cross_origin_resource_policy'])) {
            $response->headers->set('Cross-Origin-Resource-Policy', $headers['cross_origin_resource_policy']);
        }

        // ─── Content-Security-Policy ───────────────────────────────────
        $csp = (array) config('security.csp', []);
        $cspHeader = $this->buildCspHeader($csp['directives'] ?? []);

        if ($cspHeader !== '') {
            $headerName = !empty($csp['enforce'])
                ? 'Content-Security-Policy'
                : 'Content-Security-Policy-Report-Only';

            if (!empty($csp['report_uri'])) {
                $cspHeader .= '; report-uri ' . $csp['report_uri'];
            }

            $response->headers->set($headerName, $cspHeader);
        }

        // Request ID propagation (useful for Grafana/Loki log correlation)
        if (!$response->headers->has('X-Request-ID')) {
            $response->headers->set(
                'X-Request-ID',
                (string) ($request->headers->get('X-Request-ID') ?: bin2hex(random_bytes(8)))
            );
        }

        return $response;
    }

    /**
     * Build a CSP header string from a directive map.
     * `null` values emit the directive name alone (e.g. upgrade-insecure-requests).
     */
    private function buildCspHeader(array $directives): string
    {
        $parts = [];
        foreach ($directives as $name => $values) {
            if ($values === null) {
                $parts[] = $name;
                continue;
            }

            $values = array_values(array_filter((array) $values));
            if (empty($values)) {
                continue;
            }

            $parts[] = $name . ' ' . implode(' ', $values);
        }
        return implode('; ', $parts);
    }
}
