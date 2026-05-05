<?php

/*
 * CORS configuration.
 *
 * Production must use explicit origins — a wildcard combined with
 * `supports_credentials: true` is rejected by browsers per the CORS spec
 * and is a Tier 1 security finding.
 *
 * Origins are sourced from env (CORS_ALLOWED_ORIGINS, comma separated).
 * When the env is empty we fall back to safe localhost defaults *only*
 * if the app is running in local / testing. In production we fail closed
 * (empty allowlist = no cross-origin requests permitted).
 */

$envOrigins = env('CORS_ALLOWED_ORIGINS', '');
$allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $envOrigins))));

if (empty($allowedOrigins) && in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)) {
    $allowedOrigins = [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:8000',
    ];
}

$envMethods = env('CORS_ALLOWED_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS');
$allowedMethods = array_values(array_filter(array_map('trim', explode(',', $envMethods))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],
    'allowed_methods' => $allowedMethods,
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
        'X-Tenant-ID',
    ],
    'exposed_headers' => ['X-Request-ID'],
    'max_age' => (int) env('CORS_MAX_AGE', 3600),
    'supports_credentials' => true,
];
