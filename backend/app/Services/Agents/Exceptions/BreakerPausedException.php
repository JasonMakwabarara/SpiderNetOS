<?php

declare(strict_types=1);

namespace App\Services\Agents\Exceptions;

class BreakerPausedException extends AgentRuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct("Agent circuit breaker is paused: {$reason}", 'circuit_breaker_paused', 423, ['reason' => $reason]);
    }
}
