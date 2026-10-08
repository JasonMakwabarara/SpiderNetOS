<?php

/**
 * SpiderNet OS — business-launch pack ("Atlas, I want to start a business",
 * plan D7 §5). Feature flags live in config/features.php (launch.*); this file
 * holds the wiring for App\Services\Launch\*.
 */
return [

    'pack_id' => 'business-launch',

    // Deterministic finance model + docgen + checklists
    // (intelligence/services/business_launch/app.py).
    'service_url' => env('BUSINESS_LAUNCH_URL', 'http://localhost:9010'),
    'timeout' => (int) env('BUSINESS_LAUNCH_TIMEOUT', 60),

    // Sent as X-Internal-Key; the Python service enforces it when set.
    'internal_key' => env('BACKEND_INTERNAL_KEY', ''),

    // Where binary deliverables (model.xlsx, business-plan.docx/.pdf) are
    // stored: launch/<tenant>/<launch>/<file>. Brain files stay in brain_files.
    'disk' => env('BUSINESS_LAUNCH_DISK', 'local'),

    // Horizon of the finance model in months (1..120).
    'model_months' => (int) env('BUSINESS_LAUNCH_MODEL_MONTHS', 36),

    // Formats requested from POST /docgen/render; unavailable renderers are
    // reported per format, never fatal.
    'plan_formats' => ['md', 'docx', 'pdf'],

];
