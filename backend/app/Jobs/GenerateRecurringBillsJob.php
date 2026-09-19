<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\RecurringBillTemplate;
use App\Services\EventStore;
use App\Services\Spend\BillService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Daily: turn due recurring templates into draft bills (autocreate) or a
 * bill.recurring_due event (manual). Exactly-once per period is enforced
 * twice over: next_run_date advances past today after each run, and the
 * period key (Y-m monthly / o-W ISO week) is remembered in last_period_key
 * so a manually rewound next_run_date cannot double-generate a period.
 */
class GenerateRecurringBillsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(BillService $bills, EventStore $eventStore): void
    {
        $today = now()->toDateString();

        $templates = RecurringBillTemplate::enabled()
            ->whereDate('next_run_date', '<=', $today)
            ->orderBy('next_run_date')
            ->get();

        foreach ($templates as $template) {
            try {
                $this->process($template, $bills, $eventStore);
            } catch (\Throwable $e) {
                Log::error('GenerateRecurringBillsJob: template failed', [
                    'template_id' => $template->id,
                    'tenant_id' => $template->tenant_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function process(RecurringBillTemplate $template, BillService $bills, EventStore $eventStore): void
    {
        $runDate = $template->next_run_date->copy();
        $periodKey = $this->periodKey($template->cadence, $runDate);

        if ($template->last_period_key === $periodKey) {
            // Period already generated (next_run_date was rewound) — just advance.
            $template->update(['next_run_date' => $this->advance($template, $runDate)->toDateString()]);

            return;
        }

        if ($template->autocreate) {
            $bills->createBill($template->tenant_id, [
                'vendor_id' => $template->vendor_id,
                'currency' => $template->currency,
                'due_date' => $runDate->toDateString(),
                'notes' => "Generated from recurring template {$template->name}",
                'metadata' => ['recurring_bill_template_id' => $template->id, 'period_key' => $periodKey],
            ], [[
                'description' => $template->name,
                'quantity' => '1',
                'unit_price' => (string) $template->amount,
                'category_id' => $template->category_id,
            ]]);
        } else {
            $eventStore->append(
                $template->tenant_id,
                'recurring_bill_template',
                $template->id,
                'bill.recurring_due',
                [
                    'template_id' => $template->id,
                    'name' => $template->name,
                    'amount' => (string) $template->amount,
                    'currency' => $template->currency,
                    'period_key' => $periodKey,
                    'due_date' => $runDate->toDateString(),
                ]
            );
        }

        $template->update([
            'last_period_key' => $periodKey,
            'next_run_date' => $this->advance($template, $runDate)->toDateString(),
        ]);
    }

    private function periodKey(string $cadence, Carbon $runDate): string
    {
        return $cadence === 'weekly'
            ? $runDate->format('o-W')   // ISO year-week
            : $runDate->format('Y-m');
    }

    private function advance(RecurringBillTemplate $template, Carbon $runDate): Carbon
    {
        if ($template->cadence === 'weekly') {
            return $runDate->copy()->addDays(7);
        }

        // Monthly: advance to the next month, clamping day_of_month to the
        // month's length (e.g. day 31 -> Feb 28).
        $base = $runDate->copy()->startOfMonth()->addMonth();
        $day = $template->day_of_month ?: $runDate->day;

        return $base->day(min($day, $base->daysInMonth));
    }
}
