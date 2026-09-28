<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\FeatureFlag;
use App\Services\ZetKai\ZetKaiSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The nightly ZetKai pull (plan D7 §4).
 *
 * One page per tenant per night, cursor-driven. A vault that has not changed
 * costs one request; a vault that has changed a lot catches up over successive
 * nights rather than filling the brain in one pass.
 *
 * A tenant whose sync fails does not stop the others — this runs for
 * everybody, and one unreachable ZetKai instance is that tenant's problem.
 */
class ZetKaiSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 600;

    public function __construct(private readonly ?string $onlyTenantId = null) {}

    public function handle(ZetKaiSyncService $sync): void
    {
        $tenants = Tenant::where('status', 'active')
            ->when($this->onlyTenantId !== null, fn ($q) => $q->where('id', $this->onlyTenantId))
            ->pluck('id');

        foreach ($tenants as $tenantId) {
            $tenantId = (string) $tenantId;

            if (! FeatureFlag::on('zetkai.enabled', $tenantId)) {
                continue;
            }
            if ($this->onlyTenantId === null && ! FeatureFlag::on('zetkai.nightly_sync', $tenantId)) {
                continue;
            }

            try {
                $result = $sync->sync($tenantId);
                if ($result['synced']) {
                    Log::info('zetkai.nightly_sync', ['tenant_id' => $tenantId] + $result);
                }
            } catch (\Throwable $e) {
                Log::error('zetkai.nightly_sync_failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
            }
        }
    }
}
