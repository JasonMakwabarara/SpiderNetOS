<?php

declare(strict_types=1);

namespace App\Services\Agents\Exceptions;

class RuntimeDisabledException extends AgentRuntimeException
{
    public function __construct(string $tenantId)
    {
        parent::__construct('The agent runtime is disabled for this tenant (agents.runtime flag / AGENTS_RUNTIME_ENABLED).', 'agents_runtime_disabled', 503, ['tenant_id' => $tenantId]);
    }
}
