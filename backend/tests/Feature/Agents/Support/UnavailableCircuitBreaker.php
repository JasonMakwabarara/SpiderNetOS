<?php

declare(strict_types=1);

namespace Tests\Feature\Agents\Support;

/**
 * A breaker whose store cannot be read, and one that blows up when asked.
 * Both are the same question for a caller about to send something: the
 * permission check has no answer.
 */
final class UnavailableCircuitBreaker
{
    public function __construct(private readonly bool $throwInstead = false) {}

    public function available(): bool
    {
        return $this->throwInstead;
    }

    public function isPaused(string $tenantId, ?string $agentId = null, ?string $skillSlug = null, ?string $toolRisk = null): ?string
    {
        throw new \RuntimeException('SQLSTATE[08006] connection refused');
    }
}
