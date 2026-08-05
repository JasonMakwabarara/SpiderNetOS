<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Spend\Accounting\GlPostingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Stage 3: post an approved expense report / paid bill to the general ledger.
 * Dispatched afterCommit by SpendAutomationProjection on
 * expense_report.approved and bill.paid. Safe to retry — gl_postings'
 * (tenant, source_type, source_id) unique key makes posting exactly-once.
 */
class PostToGeneralLedgerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $sourceType,
        public readonly string $sourceId,
    ) {}

    public function handle(GlPostingService $postings): void
    {
        match ($this->sourceType) {
            'expense_report' => $postings->postExpenseReport($this->tenantId, $this->sourceId),
            'bill' => $postings->postBill($this->tenantId, $this->sourceId),
            default => null,
        };
    }
}
