<?php

return [
    // Kill-switch for the public self-serve registration funnel.
    'self_serve_enabled' => env('ENTERPRISE_SELF_SERVE_ENABLED', true),

    // When true, verify-domain passes without a real DNS TXT lookup.
    // Keep ON for staging/demo, OFF in production.
    'auto_verify_domains' => env('ENTERPRISE_AUTO_VERIFY_DOMAINS', env('APP_ENV', 'production') !== 'production'),

    // Master switch for DEMO auth flows on /api/enterprise/auth/* (demo SSO IdP,
    // WebAuthn stub, TOTP 000000 bypass, magic-link dev_link in responses).
    // Demo flows only ever sign in EXISTING users. Keep OFF in production.
    'demo_auth_enabled' => env('ENTERPRISE_DEMO_AUTH', env('APP_ENV', 'production') !== 'production'),

    // Kill-switch for real TOTP-as-a-sign-in-method (enrolled users only).
    'totp_login_enabled' => env('ENTERPRISE_TOTP_LOGIN_ENABLED', true),

    'magic_link_expiry_minutes' => (int) env('ENTERPRISE_MAGIC_LINK_EXPIRY_MINUTES', 30),

    // Absolute URL of the marketing-site sign-in page (magic-link emails and the
    // SSO callback redirect land here).
    'signin_url' => env('ENTERPRISE_SIGNIN_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/sign-in'),
];
