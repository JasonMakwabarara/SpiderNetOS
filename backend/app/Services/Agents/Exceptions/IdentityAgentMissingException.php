<?php

declare(strict_types=1);

namespace App\Services\Agents\Exceptions;

class IdentityAgentMissingException extends AgentRuntimeException
{
    public function __construct(string $identity, ?string $agentSlug)
    {
        parent::__construct(
            "No agents row for identity [{$identity}]".($agentSlug ? " (expected slug {$agentSlug})" : '').'. Install the pack that provisions it first.',
            'identity_agent_missing',
            422,
            ['identity' => $identity, 'agent_slug' => $agentSlug],
        );
    }
}
