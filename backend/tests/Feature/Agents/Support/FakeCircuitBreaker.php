<?php

declare(strict_types=1);

namespace Tests\Feature\Agents\Support;

/**
 * Stand-in for App\Services\Agents\AgentCircuitBreaker bound under its
 * class name (Collaborators resolves it duck-typed). Set $reason to pause.
 */
final class FakeCircuitBreaker
{
    /** @var list<array<string, mixed>> */
    public array $checks = [];

    public function __construct(public ?string $reason = null) {}

    public function isPaused(string $tenantId, ?string $agentId = null, ?string $skillSlug = null, ?string $toolRisk = null): ?string
    {
        $this->checks[] = ['tenant_id' => $tenantId, 'agent_id' => $agentId, 'skill_slug' => $skillSlug, 'tool_risk' => $toolRisk];

        return $this->reason;
    }
}
