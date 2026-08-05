<?php

return [
    /*
    | Comma-separated exact origins allowed to call GET /api/public/traces|approvals
    | from a browser (Origin header). Leave empty to rely on config/cors.php only.
    */
    'public_share_allowed_origins' => env('PUBLIC_SHARE_ALLOWED_ORIGINS', ''),

    /*
    | How agent_step flow nodes (SOP steps owned by agents) execute:
    |   auto      — inference when INFERENCE_URL is configured, else simulate
    |   inference — always call the inference plane (failures fail the node)
    |   simulate  — deterministic offline results, marked simulated:true
    */
    'agent_step_execution' => env('SPIDERNET_AGENT_STEP_EXECUTION', 'auto'),

    // Per-step LLM spend cap, enforced by the inference plane's policy router.
    'agent_step_cost_ceiling' => (float) env('SPIDERNET_AGENT_STEP_COST_CEILING', 0.25),

    // Nodes stuck in `running` longer than this are failed by the watchdog.
    'node_stale_after_minutes' => (int) env('SPIDERNET_NODE_STALE_MINUTES', 15),
];
