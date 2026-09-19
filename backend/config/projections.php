<?php

use App\Services\Projections\AgentProjection;
use App\Services\Projections\ConversationReplyBridgeProjection;
use App\Services\Projections\FlowProjection;
use App\Services\Projections\OutreachReplyProjection;
use App\Services\Projections\SpendAutomationProjection;
use App\Services\Projections\StateTransitionProjection;
use App\Services\Projections\UsageProjection;

return [
    'projectors' => [
        \App\Services\Projections\AgentProjection::class,
        \App\Services\Projections\FlowProjection::class,
        \App\Services\Projections\UsageProjection::class,
        \App\Services\Projections\StateTransitionProjection::class,
        \App\Services\Projections\SpendAutomationProjection::class,
        \App\Services\Projections\ConversationReplyBridgeProjection::class,
        \App\Services\Projections\OutreachReplyProjection::class,
        \App\Services\Projections\BrainProjection::class,
    ],

    'map' => [
        'agents' => AgentProjection::class,
        'flows' => FlowProjection::class,
        'usage_records' => UsageProjection::class,
        'ste_transitions' => StateTransitionProjection::class,
        'merchant_category_map' => SpendAutomationProjection::class,
    ],
];
