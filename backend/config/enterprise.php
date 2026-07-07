<?php

return [
    // Kill-switch for the public self-serve registration funnel.
    'self_serve_enabled' => env('ENTERPRISE_SELF_SERVE_ENABLED', true),

    // When true, verify-domain passes without a real DNS TXT lookup.
    // Keep ON for staging/demo, OFF in production.
    'auto_verify_domains' => env('ENTERPRISE_AUTO_VERIFY_DOMAINS', env('APP_ENV', 'production') !== 'production'),
];
