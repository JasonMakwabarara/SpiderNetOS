<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Bill;
use App\Services\Notifications\NotificationService;
use App\Services\Spend\BillService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily 08:00: a digest notification to tenant admins for every tenant with
 * unpaid bills due within the next 7 days (including overdue).
 */
class NotifyBillsDueSoonJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly int $days = 7,
    ) {}

    public function handle(BillService $bills, NotificationService $notifications): void
    {
        $tenantIds = Bill::query()
            ->whereNotIn('status', ['paid', 'void'])
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $due = $bills->getDueSoon($tenantId, $this->days);

            if ($due->isEmpty()) {
                continue;
            }

            $total = $due->reduce(
                fn (string $carry, Bill $bill) => bcadd($carry, (string) $bill->total_amount, 4),
                '0'
            );

            try {
                $notifications->notifyTenantRole($tenantId, ['admin', 'super_admin'], 'bill_due_soon', [
                    'title' => 'Bills due soon',
                    'body' => sprintf(
                        '%d bill%s due within %d days (total %s).',
                        $due->count(),
                        $due->count() === 1 ? '' : 's',
                        $this->days,
                        $total,
                    ),
                    'url' => '/spend/bills?due=soon',
                ]);
            } catch (\Throwable $e) {
                Log::warning('NotifyBillsDueSoonJob: notification failed', [
                    'tenant_id' => $tenantId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
