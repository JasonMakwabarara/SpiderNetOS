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
 * GenerateDailyBriefJob
 *
 * Publishes a `daily_brief` dispatch message to the Redis agent queue for every
 * active tenant.  The Python intelligence worker (Atlas agent) picks these up
 * and generates the morning briefing that surfaces on the dashboard.
 *
 * Scheduled daily via `console.php` (typically 06:00 UTC).
 */
class GenerateDailyBriefJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    private string $targetDate;

    /**
     * @param string|null $date  ISO date override (Y-m-d). Defaults to today.
     */
    public function __construct(?string $date = null)
    {
        $this->targetDate = $date ?? now()->toDateString();
    }

    public function handle(): void
    {
        $tenants = DB::table('tenants')
            ->where('status', 'active')
            ->pluck('id');

        if ($tenants->isEmpty()) {
            Log::info('[DailyBrief] No active tenants — skipping.');
            return;
        }

        $dispatched = 0;

        foreach ($tenants as $tenantId) {
            $message = json_encode([
                'id'        => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'agent_id'  => 'atlas',
                'intent'    => 'daily_brief',
                'context'   => [
                    'date'         => $this->targetDate,
                    'requested_at' => now()->toIso8601String(),
                ],
                'priority'  => 'low',
                'version'   => '3.2',
            ]);

            Redis::rpush('agent:dispatch', $message);
            $dispatched++;
        }

        Log::info("[DailyBrief] Dispatched {$dispatched} daily_brief messages for {$this->targetDate}.");
    }
}
