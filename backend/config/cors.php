<?php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'register', 'auth/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['http://localhost:3009', 'http://localhost:8000', 'https://spidernetos.com'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
