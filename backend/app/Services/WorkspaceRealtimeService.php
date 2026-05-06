<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\PaletteLanesRefresh;
use Illuminate\Support\Facades\Log;

/**
 * Pushes lightweight realtime hints to the Cockpit (Soketi / Pusher).
 */
class WorkspaceRealtimeService
{
    public function notifyPaletteLanesRefresh(string $tenantId): void
    {
        $driver = (string) config('broadcasting.default', 'null');
        if ($driver === '' || $driver === 'null' || $driver === 'log') {
            return;
        }

        try {
            broadcast(new PaletteLanesRefresh($tenantId));
        } catch (\Throwable $e) {
            Log::warning('palette.lanes.refresh broadcast failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
