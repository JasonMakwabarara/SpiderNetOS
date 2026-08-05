<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Spend\BillService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Every 15 minutes: bills whose scheduled payment date has arrived get a
 * bill_payment_due notification to tenant admins (once per day per bill).
 * Record-only V1 — the sweep NOTIFIES, it never auto-pays.
 */
class SweepScheduledBillPaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function handle(BillService $bills): void
    {
        $count = $bills->sweepScheduledPayments();

        if ($count > 0) {
            Log::info('SweepScheduledBillPaymentsJob: notified due bill payments', ['count' => $count]);
        }
    }
}
