<?php

namespace App\Jobs;

use App\Services\Founder\FounderBriefService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GenerateDailyBriefJob
 *
 * Composes the deterministic "Needs-You Today" brief (FounderBriefService,
 * plan D8 #3) for every active tenant and files it under reports/daily/.
 * This replaces the dead `daily_brief` Redis intent the Python plane never
 * consumed; the class name is kept so the schedule and callers stay valid.
 * Delivery (brief_ready at 06:55–07:05 tenant-local) is FounderBriefJob's.
 *
 * Scheduled daily via `console.php` (06:00 UTC).
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

    public function handle(FounderBriefService $briefs): void
    {
        $tenants = DB::table('tenants')
            ->where('status', 'active')
            ->pluck('id');

        if ($tenants->isEmpty()) {
            Log::info('[DailyBrief] No active tenants — skipping.');
            return;
        }

        $for = Carbon::parse($this->targetDate);
        $composed = 0;

        foreach ($tenants as $tenantId) {
            try {
                $brief = $briefs->compose((string) $tenantId, $for);
                $composed++;
                Log::info('[DailyBrief] Composed', [
                    'tenant_id' => $tenantId,
                    'date' => $brief['date'],
                    'items' => count($brief['items']),
                    'path' => $brief['path'],
                ]);
            } catch (\Throwable $e) {
                Log::error('[DailyBrief] Failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
            }
        }

        Log::info("[DailyBrief] Composed {$composed} brief(s) for {$this->targetDate}.");
    }
}
