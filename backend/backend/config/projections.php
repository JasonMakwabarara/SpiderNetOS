<?php

return [
    'projectors' => [
        \App\Services\Projections\AgentProjection::class,
        \App\Services\Projections\FlowProjection::class,
        \App\Services\Projections\UsageProjection::class,
        \App\Services\Projections\StateTransitionProjection::class,
    ],

    'map' => [
        'agents'          => \App\Services\Projections\AgentProjection::class,
        'flows'           => \App\Services\Projections\FlowProjection::class,
        'usage_records'   => \App\Services\Projections\UsageProjection::class,
        'ste_transitions' => \App\Services\Projections\StateTransitionProjection::class,
    ],
];
