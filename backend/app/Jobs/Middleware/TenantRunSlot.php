<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Caps concurrent skill runs per tenant (config agents.max_concurrent_per_tenant)
 * with Redis::funnel. Every tenant's workspaces run at once up to that many;
 * a job that cannot get a slot is released back onto the queue. No-op when
 * the queue is sync (tests, agents:run --sync) or Redis is unreachable —
 * the cap is a fairness guard, never a correctness gate.
 */
final class TenantRunSlot
{
    public function __construct(
        private readonly ?string $tenantId,
        private readonly ?int $limit = null,
        private readonly int $blockSeconds = 5,
        private readonly int $releaseSeconds = 10,
    ) {}

    public function handle(object $job, callable $next): void
    {
        if ($this->tenantId === null || $this->tenantId === '' || ! $this->funnelAvailable($job)) {
            $next($job);

            return;
        }

        $limit = max(1, $this->limit ?? (int) config('agents.max_concurrent_per_tenant', 4));

        Redis::funnel('agents:slot:'.$this->tenantId)
            ->limit($limit)
            ->block($this->blockSeconds)
            ->then(function () use ($job, $next) {
                $next($job);
            }, function () use ($job) {
                if (method_exists($job, 'release')) {
                    $job->release($this->releaseSeconds);
                }
            });
    }

    private function funnelAvailable(object $job): bool
    {
        $connection = method_exists($job, 'connection') ? null : ($job->connection ?? null);
        $default = (string) config('queue.default', 'sync');
        if (($connection ?? $default) === 'sync' || $default === 'sync') {
            return false;
        }

        try {
            Redis::connection()->ping();

            return true;
        } catch (\Throwable $e) {
            Log::debug('TenantRunSlot: Redis unavailable, running without a slot', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
