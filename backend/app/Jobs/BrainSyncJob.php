<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Brain\BrainSyncService;
use App\Services\FeatureFlag;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Hourly safety net for the Knowledge brain: re-projects business context
 * for every active tenant whose source tables changed since the last sync
 * (BrainProjection handles the live path; this catches anything that
 * bypassed the event log). Pass a tenant id to sync just that tenant.
 */
class BrainSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $tenantId = null,
        public readonly bool $force = false,
    ) {}

    public function handle(BrainSyncService $sync): void
    {
        $tenantIds = $this->tenantId !== null
            ? [$this->tenantId]
            : Tenant::where('status', 'active')->orderBy('created_at')->pluck('id')->map(fn ($id) => (string) $id)->all();

        foreach ($tenantIds as $tenantId) {
            try {
                if (! FeatureFlag::on('brain.enabled', $tenantId)) {
                    continue;
                }
                if ($this->force) {
                    $sync->syncAll($tenantId);
                } else {
                    $sync->syncIfStale($tenantId);
                }
            } catch (\Throwable $e) {
                Log::warning('brain.sync.job_failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
            }
        }
    }
}
