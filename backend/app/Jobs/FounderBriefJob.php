<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Founder\FounderBriefService;
use App\Services\Notifications\NotificationBundler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Hourly sweep that composes and delivers Needs-You Today (plan D8 #3) to
 * each tenant once a day inside the 06:55–07:05 window of the tenant's own
 * timezone (the sweep's :00 tick lands on 07:00 for whole-hour zones; the
 * rest of hour 7 is tolerated for half-hour zones), then flushes every
 * notification bundle that is due (D8 #4). Same pattern as
 * OutreachDailyDigestJob.
 */
class FounderBriefJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 300;

    public const SEND_HOUR = 7;

    public function handle(FounderBriefService $briefs, NotificationBundler $bundler): void
    {
        $tenants = Tenant::where('status', 'active')->whereNotNull('onboarding_completed_at')->get();

        foreach ($tenants as $tenant) {
            if (! self::dueNow($tenant)) {
                continue;
            }

            $key = self::sentKey($tenant);
            if (Cache::has($key)) {
                continue;
            }

            try {
                $brief = $briefs->compose((string) $tenant->id);
                $briefs->notifyReady((string) $tenant->id, $brief);
                Cache::put($key, true, now()->addHours(26));
                Log::info('founder.brief.sent', ['tenant_id' => $tenant->id, 'items' => count($brief['items']), 'path' => $brief['path']]);
            } catch (\Throwable $e) {
                Log::error('founder.brief.failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }

        try {
            $flushed = $bundler->flushDue();
            if ($flushed > 0) {
                Log::info('notifications.bundles.flushed', ['count' => $flushed]);
            }
        } catch (\Throwable $e) {
            Log::error('notifications.bundles.failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * True during the one hourly tick that lands in the tenant's morning
     * window: 06:55–07:05 local, tolerating the rest of hour 7 for
     * half-hour offsets whose :00 tick never hits 07:00.
     */
    public static function dueNow(Tenant $tenant, ?\DateTimeInterface $now = null): bool
    {
        $zone = FounderBriefService::timezoneOf($tenant);
        $now = $now ? Carbon::instance($now) : now();

        try {
            $local = $now->copy()->setTimezone($zone);
        } catch (\Throwable) {
            $local = $now->copy();
        }

        return ($local->hour === self::SEND_HOUR - 1 && $local->minute >= 55) || $local->hour === self::SEND_HOUR;
    }

    private static function sentKey(Tenant $tenant): string
    {
        $zone = FounderBriefService::timezoneOf($tenant);

        return 'founder_brief:sent:'.$tenant->id.':'.now()->setTimezone($zone)->toDateString();
    }
}
