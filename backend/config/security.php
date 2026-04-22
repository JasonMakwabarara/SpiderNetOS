<?php

/*
 * Central security configuration.
 *
 * Consumed by:
 *   - App\Http\Middleware\SecurityHeadersMiddleware   (response headers)
 *   - App\Providers\RouteServiceProvider              (rate-limit tiers)
 *
 * All values are env-driven so that dev/staging/prod can differ without a
 * code change. Production defaults fail closed.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP security headers
    |--------------------------------------------------------------------------
    |
    | HSTS is only emitted when the request arrived over HTTPS. CSP is a
    | *report-only* header by default so we can roll out without breaking
    | the cockpit; flip SECURITY_CSP_ENFORCE=true once the report URI has
    | shown a clean window for 72h.
    */
    'headers' => [
        'hsts' => [
            'enabled'           => env('SECURITY_HSTS_ENABLED', true),
            'max_age'           => (int) env('SECURITY_HSTS_MAX_AGE', 31_536_000), // 1 year
            'include_subdomains'=> env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
            'preload'           => env('SECURITY_HSTS_PRELOAD', false),
        ],

        'frame_options'     => env('SECURITY_X_FRAME_OPTIONS', 'DENY'),
        'content_type'      => 'nosniff',
        'referrer_policy'   => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'permissions_policy'=> env(
            'SECURITY_PERMISSIONS_POLICY',
            'camera=(), microphone=(self), geolocation=(), payment=(), usb=()'
        ),
        'cross_origin_opener_policy'   => env('SECURITY_COOP', 'same-origin'),
        'cross_origin_resource_policy' => env('SECURITY_CORP', 'same-site'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | The cockpit is a Vue/Vite SPA that talks to /api/* on the same origin
    | and /broadcasting/auth via Pusher/Soketi. `connect-src` therefore needs
    | self + ws(s). We do not allow inline scripts in production; the cockpit
    | emits hashed bundles only.
    */
    'csp' => [
        'enforce'    => env('SECURITY_CSP_ENFORCE', false),
        'report_uri' => env('SECURITY_CSP_REPORT_URI', ''),

        'directives' => [
            'default-src'   => ["'self'"],
            'script-src'    => ["'self'"],
            'style-src'     => ["'self'", "'unsafe-inline'"], // Vite dev injects inline styles
            'img-src'       => ["'self'", 'data:', 'https:'],
            'font-src'      => ["'self'", 'data:'],
            'connect-src'   => array_values(array_filter([
                "'self'",
                env('INFERENCE_URL'),
                env('PUSHER_SCHEME') && env('PUSHER_HOST') && env('PUSHER_PORT')
                    ? sprintf('%s://%s:%s', env('PUSHER_SCHEME'), env('PUSHER_HOST'), env('PUSHER_PORT'))
                    : null,
                'ws://localhost:*',
                'wss://localhost:*',
            ])),
            'frame-ancestors'=> ["'none'"],
            'form-action'    => ["'self'"],
            'base-uri'       => ["'self'"],
            'object-src'     => ["'none'"],
            'upgrade-insecure-requests' => null, // presence-only directive
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiter tiers
    |--------------------------------------------------------------------------
    |
    | Consumed by RouteServiceProvider::configureRateLimiting().
    | Tune per environment via env.
    */
    'rate_limits' => [
        'auth'          => (int) env('RATE_LIMIT_AUTH', 5),        // per minute
        'api'           => (int) env('RATE_LIMIT_API', 60),
        'platform'      => (int) env('RATE_LIMIT_PLATFORM', 20),
        'admin'         => (int) env('RATE_LIMIT_ADMIN', 30),
        'atlas_chat'    => (int) env('RATE_LIMIT_ATLAS_CHAT', 30),
        // Voice webhooks are signed by Twilio; throttling happens upstream.
        'voice_webhook' => (int) env('RATE_LIMIT_VOICE_WEBHOOK', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Paths excluded from security middleware
    |--------------------------------------------------------------------------
    */
    'excluded_paths' => [
        'up',          // Laravel native health endpoint
    ],
];
