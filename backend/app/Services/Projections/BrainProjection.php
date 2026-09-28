<?php

declare(strict_types=1);

namespace App\Services\Projections;

use App\Models\Event;
use App\Services\Brain\BrainSyncService;
use App\Services\FeatureFlag;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the Knowledge brain in step with the tables it projects from: any
 * funnel-setup, business-profile, outreach-settings or systemization event
 * triggers BrainSyncService::syncIfStale() for that tenant. Best-effort —
 * a failure is logged and never blocks the event write.
 */
class BrainProjection
{
    private const PREFIXES = ['pack.sales-crm.funnel_setup.', 'systemization.'];

    private const EXACT = ['tenant.business_profile.updated', 'outreach.settings.updated'];

    public function accepts(Event $event): bool
    {
        $type = (string) $event->event_type;
        if (in_array($type, self::EXACT, true)) {
            return true;
        }
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function handle(Event $event): void
    {
        $tenantId = (string) $event->tenant_id;
        if ($tenantId === '') {
            return;
        }

        try {
            if (! FeatureFlag::on('brain.enabled', $tenantId)) {
                return;
            }
            app(BrainSyncService::class)->syncIfStale($tenantId);
        } catch (\Throwable $e) {
            Log::warning('[BrainProjection] sync failed', [
                'event_id' => $event->id ?? null,
                'event_type' => $event->event_type,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
