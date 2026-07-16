<?php

/*
|--------------------------------------------------------------------------
| Dodo Payments — platform billing gateway
|--------------------------------------------------------------------------
| SpiderNetOS charges tenants through Dodo Payments (merchant of record).
| Plans map the tenant `plan` column to a Dodo product. Product IDs are
| created in the Dodo dashboard (or synced via `spidernet:dodo-sync`) and
| injected through environment variables so no pricing is hardcoded per
| environment.
|
| Webhooks follow the Standard Webhooks spec (webhook-id / webhook-timestamp
| / webhook-signature headers, HMAC-SHA256, whsec_-prefixed base64 secret).
*/

return [
    'enabled' => env('DODO_ENABLED', false),

    // 'test' → test.dodopayments.com, 'live' → live.dodopayments.com
    'mode' => env('DODO_MODE', 'test'),

    'api_key' => env('DODO_API_KEY', ''),
    'webhook_secret' => env('DODO_WEBHOOK_SECRET', ''),

    'base_urls' => [
        'test' => env('DODO_TEST_URL', 'https://test.dodopayments.com'),
        'live' => env('DODO_LIVE_URL', 'https://live.dodopayments.com'),
    ],

    // Where Dodo sends the customer after checkout completes.
    'return_url' => env('DODO_RETURN_URL', env('APP_URL', 'http://localhost:8000') . '/billing/return'),

    // Reject webhooks whose timestamp drifts more than this many seconds.
    'webhook_tolerance_seconds' => (int) env('DODO_WEBHOOK_TOLERANCE', 300),

    /*
    | Tenant plan → Dodo product mapping. `limits` is written onto the
    | tenant on activation and consumed by EnforcePlanLimits/CostGovernor.
    */
    'plans' => [
        'starter' => [
            'label' => 'Starter',
            'product_id' => env('DODO_PRODUCT_STARTER', ''),
            'price_usd' => (float) env('DODO_PRICE_STARTER', 1200),
            'interval' => 'month',
            'limits' => ['agents' => 5, 'flows' => 10, 'daily_budget_usd' => 25],
        ],
        'growth' => [
            'label' => 'Growth',
            'product_id' => env('DODO_PRODUCT_GROWTH', ''),
            'price_usd' => (float) env('DODO_PRICE_GROWTH', 4800),
            'interval' => 'month',
            'limits' => ['agents' => 25, 'flows' => 100, 'daily_budget_usd' => 150],
        ],
        'enterprise' => [
            'label' => 'Enterprise',
            'product_id' => env('DODO_PRODUCT_ENTERPRISE', ''),
            'price_usd' => null, // custom — sales-assisted
            'interval' => 'month',
            'limits' => ['agents' => 250, 'flows' => 1000, 'daily_budget_usd' => 1000],
        ],
    ],
];
