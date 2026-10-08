<?php

return [
    // Off by default. Sandbox submissions do not call a fiscal authority.
    'live' => (bool) env('FDMS_LIVE', false),

    'fdms' => [
        // Test: https://fdmsapitest.zimra.co.zw  Production: https://fdmsapi.zimra.co.zw
        'base_url' => env('FDMS_BASE_URL', 'https://fdmsapitest.zimra.co.zw'),
        // The device belongs to one taxpayer. Other tenants are refused.
        'tenant_id' => env('FDMS_TENANT_ID'),
        'device_id' => env('FDMS_DEVICE_ID') !== null ? (int) env('FDMS_DEVICE_ID') : null,
        'model_name' => env('FDMS_DEVICE_MODEL_NAME'),
        'model_version' => env('FDMS_DEVICE_MODEL_VERSION'),
        // ZIMRA-issued device certificate and its private key, PEM files outside the web root.
        'cert_path' => env('FDMS_CERT_PATH'),
        'key_path' => env('FDMS_KEY_PATH'),
        'key_passphrase' => env('FDMS_KEY_PASSPHRASE'),
        'timezone' => env('FDMS_TIMEZONE', 'Africa/Harare'),
        'timeout' => (int) env('FDMS_TIMEOUT', 30),
    ],
];
