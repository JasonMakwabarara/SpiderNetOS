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
        AgentProjection::class,
        FlowProjection::class,
        UsageProjection::class,
        StateTransitionProjection::class,
        SpendAutomationProjection::class,
        ConversationReplyBridgeProjection::class,
        OutreachReplyProjection::class,
    ],

    'map' => [
        'agents' => AgentProjection::class,
        'flows' => FlowProjection::class,
        'usage_records' => UsageProjection::class,
        'ste_transitions' => StateTransitionProjection::class,
        'merchant_category_map' => SpendAutomationProjection::class,
    ],
];
