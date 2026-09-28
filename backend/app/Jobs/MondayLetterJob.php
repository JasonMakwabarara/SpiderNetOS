<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NewsletterIssue;
use App\Models\Tenant;
use App\Services\FeatureFlag;
use App\Services\Notifications\NotificationService;
use App\Services\Reports\MondayLetterComposer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Hourly sweep that delivers the Monday letter (plan D8 #11) — and with it the
 * C-Suite newsletter (D8 #15) — once a week, at 07:00 on Monday in the
 * tenant's own timezone. Same shape as FounderBriefJob: the sweep runs every
 * hour and each tenant takes the tick that is 07:00 where they are.
 *
 * Delivery is in-cockpit plus a notification. The C-Suite letter is never
 * pushed to a third-party list: it carries the operating numbers of the
 * business and has exactly one reader.
 */
class MondayLetterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 300;

    public const SEND_HOUR = 7;

    public const SEND_DAY = Carbon::MONDAY;

    public function __construct(private readonly ?string $onlyTenantId = null) {}

    public function handle(MondayLetterComposer $composer, NotificationService $notifications): void
    {
        $tenants = Tenant::where('status', 'active')->whereNotNull('onboarding_completed_at')
            ->when($this->onlyTenantId !== null, fn ($q) => $q->where('id', $this->onlyTenantId))
            ->get();

        foreach ($tenants as $tenant) {
            $tenantId = (string) $tenant->id;

            if (! FeatureFlag::on('newsletter.csuite', $tenantId)) {
                continue;
            }
            if ($this->onlyTenantId === null && ! self::dueNow($tenant)) {
                continue;
            }

            try {
                $letter = $composer->compose($tenantId);

                // One letter per week, even if the sweep fires twice in the hour.
                $issue = NewsletterIssue::forTenant($tenantId)
                    ->ofKind(NewsletterIssue::KIND_CSUITE)
                    ->where('period', $letter['period'])
                    ->first();

                if ($issue !== null && $issue->sent_at !== null) {
                    continue;
                }

                $this->notify($notifications, $tenantId, $letter);

                $issue?->forceFill(['status' => NewsletterIssue::STATUS_SENT, 'sent_at' => now(), 'recipients' => 1])->save();

                Log::info('reports.monday_letter.sent', [
                    'tenant_id' => $tenantId,
                    'period' => $letter['period'],
                    'path' => $letter['paths']['letter'],
                ]);
            } catch (\Throwable $e) {
                Log::error('reports.monday_letter.failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
            }
        }
    }

    private function notify(NotificationService $notifications, string $tenantId, array $letter): void
    {
        $antlers = $letter['antlers']['title'] ?? null;

        $notifications->notifyTenantRole($tenantId, ['admin', 'owner'], 'monday_letter', [
            'title' => 'Your Monday letter is ready',
            'body' => $antlers ?? 'The week in seven numbers, plus what went right.',
            'url' => '/reports/weekly/'.$letter['period'],
            'period' => $letter['period'],
            'path' => $letter['paths']['letter'],
            'csuite_path' => $letter['paths']['csuite'],
        ]);
    }

    /** 07:00 on Monday, in the tenant's own timezone. */
    public static function dueNow(Tenant $tenant, ?Carbon $now = null): bool
    {
        $zone = MondayLetterComposer::timezoneOf($tenant);
        $local = ($now ?? now())->copy()->setTimezone($zone);

        return $local->dayOfWeek === self::SEND_DAY && $local->hour === self::SEND_HOUR;
    }
}
