<?php

/*
|--------------------------------------------------------------------------
| Spend automation (Stage 1c — AI layer)
|--------------------------------------------------------------------------
|
| Configuration for receipt/bill extraction, category suggestion and
| spend-policy automation. Tenant expense_categories extend (never replace)
| the default category list below.
*/

return [

    // Default spend categories. Tenant-defined expense_categories slugs are
    // unioned with this list wherever a category enum is needed.
    'categories' => [
        'travel',
        'meals',
        'software',
        'office',
        'marketing',
        'professional_services',
        'utilities',
        'other',
    ],

    // Suggestions below this confidence are marked requires_user_pick.
    'category_confidence_threshold' => (float) env('SPEND_CATEGORY_CONFIDENCE', 0.75),

    'extraction' => [
        'max_file_mb' => 10,
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
        ],
        // Rough per-document cost estimate used for CostGovernor pre-checks
        // when the inference plane does not report an actual cost.
        'cost_estimate' => 0.01,
        // Documents whose overall extraction confidence falls below this
        // threshold land in needs_review instead of extracted.
        'review_threshold' => 0.6,
        'model' => env('SPEND_EXTRACTION_MODEL'),
    ],

    // Keyword => category rules used as the second tier of the category
    // suggestion cascade (after merchant memory, before LLM classify).
    // First matching category in this order wins.
    'keyword_rules' => [
        'travel' => ['uber', 'lyft', 'taxi', 'rail', 'air', 'airline', 'flight', 'train', 'hotel', 'parking', 'car rental'],
        'meals' => ['restaurant', 'cafe', 'coffee', 'catering', 'diner', 'bistro', 'pizza', 'takeaway', 'food'],
        'software' => ['aws', 'azure', 'github', 'saas', 'software', 'license', 'cloud', 'hosting', 'subscription'],
        'office' => ['staples', 'office', 'supplies', 'stationery', 'printer', 'furniture'],
        'marketing' => ['ads', 'advertising', 'marketing', 'campaign', 'sponsorship', 'seo', 'branding'],
        'professional_services' => ['consulting', 'legal', 'accounting', 'attorney', 'solicitor', 'audit', 'notary'],
        'utilities' => ['electric', 'electricity', 'water', 'gas', 'internet', 'broadband', 'phone', 'telecom', 'utility'],
    ],

    // Look-back window (days) for duplicate expense detection.
    'duplicate_window_days' => 14,

    // Future: automatic GL posting of confirmed spend documents.
    'gl_posting_enabled' => (bool) env('SPEND_GL_POSTING_ENABLED', false),
];
