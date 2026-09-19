<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * WebSocketThrottler — SpiderNet OS v3.2
 *
 * Tenant-level WebSocket event batching and rate-limiting.
 * Uses Redis sliding-window counters to enforce a maximum of
 * 10 broadcast events per second per tenant.
 */
class WebSocketThrottler
{
    /** Maximum events a single tenant may broadcast per second. */
    private const MAX_EVENTS_PER_SECOND = 10;

    /** Redis key TTL in seconds (auto-expire stale counters). */
    private const COUNTER_TTL_SECONDS = 5;

    /** Redis key prefix for rate counters. */
    private const KEY_PREFIX = 'ws_throttle:';

    public function __construct(
        private readonly BroadcastManager $broadcaster,
    ) {}

    // ------------------------------------------------------------------ //
    //  Public API
    // ------------------------------------------------------------------ //

    /**
     * Determine whether a broadcast is allowed for the given tenant and
     * event type under the current rate limit.
     *
     * The method uses a Redis sorted-set sliding window:
     *   - Each event is scored by its microsecond timestamp.
     *   - Entries older than 1 second are pruned.
     *   - If the remaining cardinality is below the limit, the event passes.
     *
     * @param  string  $tenantId  Tenant scope.
     * @param  string  $eventType  Logical event name (informational — logged but
     *                             not factored into separate per-type limits).
     * @return bool True if the tenant may broadcast right now.
     */
    public function shouldBroadcast(string $tenantId, string $eventType): bool
    {
        $key = $this->redisKey($tenantId);
        $now = microtime(true);
        $windowStart = $now - 1.0; // 1-second sliding window

        // Remove entries outside the window.
        Redis::zremrangebyscore($key, '-inf', (string) $windowStart);

        // Count events remaining in the window.
        $currentCount = (int) Redis::zcard($key);

        if ($currentCount >= self::MAX_EVENTS_PER_SECOND) {
            Log::debug('WebSocket throttle hit', [
                'tenant_id' => $tenantId,
                'event_type' => $eventType,
                'rate' => $currentCount,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Broadcast an event to the tenant's WebSocket channel, subject to
     * rate-limiting.  If the tenant has exceeded the limit the event is
     * silently dropped and `false` is returned.
     *
     * @param  string  $tenantId  Tenant scope.
     * @param  string  $channel  Broadcast channel name.
     * @param  string  $event  Event name.
     * @param  array  $data  Payload data.
     * @return bool True if the event was actually broadcast.
     */
    public function broadcast(string $tenantId, string $channel, string $event, array $data = []): bool
    {
        if (! $this->shouldBroadcast($tenantId, $event)) {
            return false;
        }

        // Record this event in the sliding window.
        $this->recordEvent($tenantId);

        // Dispatch through Laravel's broadcast infrastructure.
        $this->broadcaster->connection()->send(
            json_encode([
                'channel' => $channel,
                'event' => $event,
                'data' => $data,
            ], JSON_THROW_ON_ERROR),
        );

        Log::debug('WebSocket event broadcast', [
            'tenant_id' => $tenantId,
            'channel' => $channel,
            'event' => $event,
        ]);

        return true;
    }

    /**
     * Return the current event rate (events in the last 1-second window)
     * for the given tenant.
     *
     * @return int Number of events in the current 1 s window.
     */
    public function getEventRate(string $tenantId): int
    {
        $key = $this->redisKey($tenantId);
        $windowStart = microtime(true) - 1.0;

        // Prune stale entries.
        Redis::zremrangebyscore($key, '-inf', (string) $windowStart);

        return (int) Redis::zcard($key);
    }

    // ------------------------------------------------------------------ //
    //  Internals
    // ------------------------------------------------------------------ //

    /**
     * Record a single event in the Redis sliding-window sorted set.
     */
    private function recordEvent(string $tenantId): void
    {
        $key = $this->redisKey($tenantId);
        $now = microtime(true);
        $member = $now.':'.bin2hex(random_bytes(4)); // unique member

        Redis::zadd($key, (string) $now, $member);
        Redis::expire($key, self::COUNTER_TTL_SECONDS);
    }

    /**
     * Build the Redis key for a tenant's rate counter.
     */
    private function redisKey(string $tenantId): string
    {
        return self::KEY_PREFIX.$tenantId;
    }
}
