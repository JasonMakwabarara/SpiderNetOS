<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Outreach\Ops\OutreachDigest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Hourly sweep that sends each tenant its outreach digest once a day, at 08:30
 * in the tenant's own timezone, and auto-pauses sending when the bounce rate
 * crosses the threshold. Gated per tenant by outreach.digest.
 */
class OutreachDailyDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 300;

    /** 08:00–08:59 in the tenant's timezone; the hourly sweep matches once a day. */
    public const SEND_HOUR = 8;

    public function handle(OutreachDigest $digests): void
    {
        $tenants = Tenant::where('status', 'active')->whereNotNull('onboarding_completed_at')->get()
            ->filter(fn (Tenant $t) => ! empty(((array) ($t->settings ?? []))['outreach'] ?? null));

        foreach ($tenants as $tenant) {
            if (! $this->dueNow($tenant)) {
                continue;
            }

            try {
                $result = $digests->run($tenant);
                if ($result['skipped'] === null) {
                    Log::info('outreach.digest', ['tenant_id' => $tenant->id, 'paused' => $result['paused']]);
                }
            } catch (\Throwable $e) {
                Log::error('outreach.digest.failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * True during the one hourly run that lands on 08:30 local time. The sweep
     * runs hourly, so each tenant matches once a day whatever its offset.
     */
    private function dueNow(Tenant $tenant): bool
    {
        $zone = (string) ((((array) ($tenant->settings ?? []))['outreach']['sending']['timezone'] ?? null) ?: 'UTC');

        try {
            $local = now()->setTimezone($zone);
        } catch (\Throwable) {
            $local = now();
        }

        return $local->hour === self::SEND_HOUR;
    }
}
