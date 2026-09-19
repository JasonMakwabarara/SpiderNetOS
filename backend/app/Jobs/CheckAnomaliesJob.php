<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * CheckAnomaliesJob
 *
 * Publishes an `anomaly_check` dispatch message targeting the Sentinel agent
 * for every active tenant.  The Python intelligence worker picks these up,
 * runs statistical / ML anomaly detection on recent usage and event patterns,
 * and publishes findings back through the event store.
 *
 * Scheduled to run every 15 minutes via `console.php`.
 */
class CheckAnomaliesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 15;

    public function __construct()
    {
        //
    }

    public function handle(): void
    {
        $tenants = DB::table('tenants')
            ->where('status', 'active')
            ->pluck('id');

        if ($tenants->isEmpty()) {
            Log::info('[AnomalyCheck] No active tenants — skipping.');

            return;
        }

        $dispatched = 0;

        foreach ($tenants as $tenantId) {
            // Verify the tenant has an active sentinel agent before dispatching
            $hasSentinel = DB::table('agents')
                ->where('tenant_id', $tenantId)
                ->where('slug', 'sentinel')
                ->where('status', 'active')
                ->exists();

            if (! $hasSentinel) {
                continue;
            }

            $message = json_encode([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'agent_id' => 'sentinel',
                'intent' => 'anomaly_check',
                'context' => [
                    'check_window_minutes' => 15,
                    'requested_at' => now()->toIso8601String(),
                ],
                'priority' => 'normal',
                'version' => '3.2',
            ]);

            Redis::rpush('agent:dispatch', $message);
            $dispatched++;
        }

        Log::info("[AnomalyCheck] Dispatched {$dispatched} anomaly_check messages.");
    }
}
