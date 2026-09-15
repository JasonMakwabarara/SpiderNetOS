<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'inference' => [
        'url' => env('INFERENCE_URL', 'http://localhost:9000'),
        'token' => env('INFERENCE_TOKEN', ''),
        'timeout' => (int) env('INFERENCE_TIMEOUT', 60),
        // Local checkout of the inference plane, for `inference:doctor` and
        // `voice:render-previews` (prod: INFERENCE_PATH=/opt/spidernet-inference).
        'path' => env('INFERENCE_PATH', ''),
        'python' => env('INFERENCE_PYTHON', ''),
    ],

    'intelligence_gateway' => [
        // Host dev: localhost:8005 maps to semantic-gateway container :8000
        'url' => env('INTELLIGENCE_GATEWAY_URL', 'http://localhost:8005'),
        'timeout' => (int) env('INTELLIGENCE_GATEWAY_TIMEOUT', 30),
    ],

    'dag_compiler' => [
        'url' => env('DAG_COMPILER_URL', 'http://localhost:8002'),
    ],

    'runtime_guardian' => [
        'url' => env('RUNTIME_GUARDIAN_URL', 'http://localhost:8004'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),
    ],

    'cost_governor' => [
        'default_ceiling' => (float) env('COST_CEILING_DEFAULT', 50.0),
    ],

    'spidernet' => [
        'fingerprint_cache_ttl_minutes' => (int) env('FINGERPRINT_CACHE_TTL_MINUTES', 120),
        'prompt_enhancer_model' => env('SPIDERNET_PROMPT_ENHANCER_MODEL', 'gpt-4o-mini'),
        'cost_estimate_max_drift_ratio' => (float) env('COST_ESTIMATE_MAX_DRIFT_RATIO', 0.75),
        'truth_anchor_tolerance' => (float) env('ATLAS_TRUTH_ANCHOR_TOLERANCE', 1.25),
        'atlas_default_style' => env('ATLAS_DEFAULT_STYLE', 'balanced'),

        // Transformation Score weights (B6) — sum of positive weights should be 1.0
        'ts_weights' => [
            'value'         => (float) env('ATLAS_TS_W_VALUE', 0.25),
            'clarity'       => (float) env('ATLAS_TS_W_CLARITY', 0.15),
            'emotional'     => (float) env('ATLAS_TS_W_EMOTIONAL', 0.20),
            'actionability' => (float) env('ATLAS_TS_W_ACTIONABILITY', 0.15),
            'trust'         => (float) env('ATLAS_TS_W_TRUST', 0.15),
            'cognitive'     => (float) env('ATLAS_TS_W_COGNITIVE', 0.05),
            'leakage'       => (float) env('ATLAS_TS_W_LEAKAGE', 0.05),
        ],
    ],

    'openjarvis' => [
        'enabled' => env('OPENJARVIS_ENABLED', true),
        'url' => env('OPENJARVIS_BRIDGE_URL', 'http://openjarvis-bridge:8000'),
        'timeout' => (int) env('OPENJARVIS_TIMEOUT', 60),
        // Optional: full OpenJarvis server (local-first stack)
        'server_url' => env('OPENJARVIS_URL', ''),
    ],

    // Shared secret for Python intelligence workers calling back into
    // Laravel's /api/internal/* routes (see App\Http\Middleware\VerifyInternalKey
    // and intelligence/agents/*.py BACKEND_URL / BACKEND_INTERNAL_KEY).
    'internal' => [
        'key' => env('BACKEND_INTERNAL_KEY', ''),
    ],

    // Dodo Payments — merchant of record for purchasable feature packs
    // (platform-level credentials; packs are sold BY SpiderNetOS, not via
    // per-tenant Stripe-Connect-style accounts). See
    // App\Services\Integrations\DodoPaymentsAdapter.
    'dodo' => [
        'api_key' => env('DODO_API_KEY', ''),
        'webhook_secret' => env('DODO_WEBHOOK_SECRET', ''),
        'environment' => env('DODO_ENVIRONMENT', 'test'), // test | live
        // pack_id => Dodo product_id, so pack.yaml manifests stay environment-agnostic.
        'products' => [
            'sales-crm' => env('DODO_PRODUCT_SALES_CRM', ''),
        ],
    ],

];
