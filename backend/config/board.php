<?php

/**
 * The board of advisors (plan D6 §6).
 *
 * Seats live on disk as packages/advisors/<slug>/PERSONA.md so a seat can be
 * reviewed, diffed and argued with in a pull request rather than living in a
 * database row nobody reads.
 */
return [

    // Seat definitions. Same convention as agents.skills_root.
    'advisors_root' => rtrim((string) env('ADVISORS_ROOT', dirname(base_path()).'/packages/advisors'), '/'),

    // A session above this estimated cost becomes a proposal instead of running
    // straight away: six model calls per round is not a free action.
    'max_auto_cost_usd' => (float) env('BOARD_MAX_AUTO_COST_USD', 0.75),

    // Per-seat generation budget.
    'per_seat' => [
        'max_tokens' => (int) env('BOARD_SEAT_MAX_TOKENS', 900),
        'temperature' => (float) env('BOARD_SEAT_TEMPERATURE', 0.4),
        'cost_ceiling_usd' => (float) env('BOARD_SEAT_COST_CEILING', 0.12),
    ],

    // The chairman reads every take, so it gets more room and less randomness.
    'chair' => [
        'max_tokens' => (int) env('BOARD_CHAIR_MAX_TOKENS', 1600),
        'temperature' => (float) env('BOARD_CHAIR_TEMPERATURE', 0.2),
        'cost_ceiling_usd' => (float) env('BOARD_CHAIR_COST_CEILING', 0.25),
    ],

    // Carried on every report and every API response. Not negotiable per tenant.
    'disclaimer' => 'Not legal, financial or professional advice.',

    // Brain files a session may read when no seat scope names anything else.
    'default_scopes' => [
        'business/profile.md',
        'business/alignment.md',
        'offer/offer.md',
        'customers/icp.md',
        'finance/summary.md',
        'people/user.md',
    ],
];
