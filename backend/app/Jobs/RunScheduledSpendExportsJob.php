<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SpendExportSchedule;
use App\Models\User;
use App\Services\Connectors\WebhookConnector;
use App\Services\Notifications\NotificationService;
use App\Services\Spend\Accounting\AccountingExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Daily 04:00: run enabled spend export schedules that have come due
 * (weekly => first run of the ISO week over the previous full week;
 * monthly => first run of the month over the previous calendar month).
 * Admins get a notification with the download URL; delivery 'webhook'
 * additionally POSTs export metadata via WebhookConnector (never the file).
 */
class RunScheduledSpendExportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 120;

    public function __construct(public readonly ?string $asOf = null) {}

    public function handle(AccountingExportService $exports, NotificationService $notifications): void
    {
        $now = $this->asOf ? Carbon::parse($this->asOf) : now();

        $due = SpendExportSchedule::enabled()->orderBy('created_at')->get()
            ->filter(fn (SpendExportSchedule $schedule) => $schedule->isDue($now));

        foreach ($due as $schedule) {
            try {
                $this->runSchedule($schedule, $now, $exports, $notifications);
            } catch (\Throwable $e) {
                Log::warning('RunScheduledSpendExportsJob: schedule failed', [
                    'schedule' => $schedule->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function runSchedule(
        SpendExportSchedule $schedule,
        Carbon $now,
        AccountingExportService $exports,
        NotificationService $notifications,
    ): void {
        [$from, $to] = $schedule->frequency === 'monthly'
            ? [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()]
            : [$now->copy()->subWeek()->startOfWeek(), $now->copy()->subWeek()->endOfWeek()];

        $requestedBy = User::where('tenant_id', $schedule->tenant_id)
            ->whereIn('role', ['admin', 'super_admin'])
            ->orderBy('created_at')
            ->value('id')
            ?? User::where('tenant_id', $schedule->tenant_id)->orderBy('created_at')->value('id');

        if ($requestedBy === null) {
            Log::warning('RunScheduledSpendExportsJob: tenant has no users, skipping', [
                'schedule' => $schedule->id,
            ]);

            return;
        }

        $export = $exports->generate(
            $schedule->tenant_id,
            $schedule->export_type,
            $from->toDateString(),
            $to->toDateString(),
            (string) $requestedBy,
        );

        $schedule->update(['last_run_at' => $now]);

        if (!$export->isGenerated()) {
            Log::warning('RunScheduledSpendExportsJob: export generation failed', [
                'schedule' => $schedule->id,
                'export' => $export->id,
                'error' => $export->error,
            ]);

            return;
        }

        $downloadUrl = "/api/financial/accounting/exports/{$export->id}/download";

        try {
            $notifications->notifyTenantRole($schedule->tenant_id, ['admin', 'super_admin'], 'spend_export_ready', [
                'title' => 'Accounting export ready',
                'body' => sprintf(
                    '%s export for %s to %s is ready (%d rows).',
                    $export->export_type,
                    $export->period_start->toDateString(),
                    $export->period_end->toDateString(),
                    $export->row_count,
                ),
                'url' => $downloadUrl,
            ]);
        } catch (\Throwable $e) {
            Log::warning('RunScheduledSpendExportsJob: notification failed', [
                'schedule' => $schedule->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($schedule->delivery === 'webhook') {
            $this->deliverWebhook($schedule, $export->only([
                'id', 'export_type', 'status', 'row_count',
            ]) + [
                'period_start' => $export->period_start->toDateString(),
                'period_end' => $export->period_end->toDateString(),
                'download_url' => $downloadUrl,
            ]);
        }
    }

    private function deliverWebhook(SpendExportSchedule $schedule, array $payload): void
    {
        try {
            $destination = $schedule->destination ?? [];
            if (empty($destination['url'])) {
                return;
            }

            $connector = new WebhookConnector($schedule->tenant_id, [
                'url' => (string) $destination['url'],
                'secret' => $destination['secret'] ?? null,
            ]);

            $connector->execute('send_event', [
                'event' => 'spend.export_generated',
                'data' => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::warning('RunScheduledSpendExportsJob: webhook delivery failed', [
                'schedule' => $schedule->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
