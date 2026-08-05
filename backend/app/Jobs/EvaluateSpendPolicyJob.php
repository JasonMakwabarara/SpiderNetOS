<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Budget;
use App\Models\ExpenseItem;
use App\Models\ExpensePolicy;
use App\Models\ExpenseReport;
use App\Models\FinancialAlert;
use App\Models\SpendDocument;
use App\Services\EventStore;
use App\Services\Financial\FinancialGovernor;
use App\Services\Notifications\NotificationService;
use App\Services\Spend\CategorySuggestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Deep post-submission checks on an expense report (dispatched afterCommit
 * by SpendAutomationProjection on expense_report.submitted):
 *
 *  - duplicate receipts (same sha256 elsewhere in the tenant)
 *  - duplicate items: exact (merchant+amount+date) and fuzzy
 *    (amount +/- 0.01, date +/- 3d, merchant similarity >= 85%)
 *  - split transactions (same merchant, same day, N items summing over the
 *    receipt-required threshold while each stays under it)
 *  - per-category budget checks via FinancialGovernor::checkBudgetLimit
 *
 * Advisory only: findings become an event + alert + admin notification,
 * never a block.
 */
class EvaluateSpendPolicyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [60];

    public function __construct(
        public readonly string $tenantId,
        public readonly string $reportId,
    ) {}

    public function handle(EventStore $eventStore, NotificationService $notifications): void
    {
        $report = ExpenseReport::forTenant($this->tenantId)->with('items')->find($this->reportId);

        if (!$report || in_array($report->status, ['draft', 'void'], true)) {
            return;
        }

        $windowDays = (int) config('spend.duplicate_window_days', 14);

        $findings = array_merge(
            $this->duplicateReceipts($report),
            $this->duplicateItems($report, $windowDays),
            $this->splitTransactions($report),
            $this->budgetFindings($report),
        );

        if ($findings === []) {
            return;
        }

        $eventStore->append(
            $this->tenantId,
            'expense_report',
            $report->id,
            'expense.policy_flagged',
            [
                'report_number' => $report->report_number,
                'finding_count' => count($findings),
                'findings' => $findings,
            ]
        );

        $this->createAlert($report, $findings);

        try {
            $notifications->notifyTenantRole($this->tenantId, ['admin'], 'spend.policy_violation', [
                'title' => 'Spend policy findings on '.$report->report_number,
                'body' => count($findings).' finding(s) on expense report '.$report->report_number.'.',
                'url' => '/spend/expenses/'.$report->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('EvaluateSpendPolicyJob: notification failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------ //
    //  Checks
    // ------------------------------------------------------------------ //

    private function duplicateReceipts(ExpenseReport $report): array
    {
        $findings = [];

        $documents = SpendDocument::forTenant($this->tenantId)
            ->where('attachable_type', ExpenseItem::class)
            ->whereIn('attachable_id', $report->items->pluck('id'))
            ->get();

        foreach ($documents as $doc) {
            $twin = SpendDocument::forTenant($this->tenantId)
                ->where('sha256', $doc->sha256)
                ->where('id', '!=', $doc->id)
                ->first();

            if ($twin) {
                $findings[] = [
                    'type' => 'duplicate_receipt',
                    'document_id' => $doc->id,
                    'duplicate_of' => $twin->id,
                    'sha256' => $doc->sha256,
                ];
            }
        }

        return $findings;
    }

    private function duplicateItems(ExpenseReport $report, int $windowDays): array
    {
        $findings = [];

        foreach ($report->items as $item) {
            $itemMerchant = CategorySuggestionService::normalizeMerchant((string) ($item->merchant ?? ''));
            $itemDate = $item->expense_date;

            $candidates = ExpenseItem::forTenant($this->tenantId)
                ->where('id', '!=', $item->id)
                ->whereBetween('expense_date', [
                    $itemDate->copy()->subDays($windowDays)->toDateString(),
                    $itemDate->copy()->addDays($windowDays)->toDateString(),
                ])
                ->get();

            foreach ($candidates as $candidate) {
                $candidateMerchant = CategorySuggestionService::normalizeMerchant((string) ($candidate->merchant ?? ''));
                $amountDiff = abs((float) $item->amount - (float) $candidate->amount);
                $dayDiff = abs($itemDate->diffInDays($candidate->expense_date, false));

                $exact = $itemMerchant !== ''
                    && $itemMerchant === $candidateMerchant
                    && $amountDiff < 0.005
                    && $itemDate->isSameDay($candidate->expense_date);

                if ($exact) {
                    $findings[] = [
                        'type' => 'duplicate_item_exact',
                        'item_id' => $item->id,
                        'duplicate_of' => $candidate->id,
                        'merchant' => $item->merchant,
                        'amount' => (string) $item->amount,
                    ];

                    continue;
                }

                if ($itemMerchant === '' || $candidateMerchant === '') {
                    continue;
                }

                similar_text($itemMerchant, $candidateMerchant, $percent);

                if ($amountDiff <= 0.01 && $dayDiff <= 3 && $percent >= 85.0) {
                    $findings[] = [
                        'type' => 'duplicate_item_fuzzy',
                        'item_id' => $item->id,
                        'duplicate_of' => $candidate->id,
                        'merchant_similarity' => round($percent, 1),
                        'amount_diff' => round($amountDiff, 4),
                        'day_diff' => $dayDiff,
                    ];
                }
            }
        }

        return $findings;
    }

    private function splitTransactions(ExpenseReport $report): array
    {
        $threshold = $this->receiptThreshold();
        if ($threshold === null) {
            return [];
        }

        $groups = [];
        foreach ($report->items as $item) {
            $merchant = CategorySuggestionService::normalizeMerchant((string) ($item->merchant ?? ''));
            if ($merchant === '') {
                continue;
            }
            $groups[$merchant.'|'.$item->expense_date->toDateString()][] = $item;
        }

        $findings = [];

        foreach ($groups as $key => $items) {
            if (count($items) < 2) {
                continue;
            }

            $sum = array_sum(array_map(fn (ExpenseItem $i) => (float) $i->amount, $items));
            $max = max(array_map(fn (ExpenseItem $i) => (float) $i->amount, $items));

            if ($sum > $threshold && $max < $threshold) {
                [$merchant, $date] = explode('|', $key, 2);

                $findings[] = [
                    'type' => 'split_transaction',
                    'merchant' => $merchant,
                    'expense_date' => $date,
                    'item_count' => count($items),
                    'item_ids' => array_map(fn (ExpenseItem $i) => $i->id, $items),
                    'sum' => round($sum, 2),
                    'threshold' => $threshold,
                ];
            }
        }

        return $findings;
    }

    /** Smallest enabled receipt-required-over threshold for the tenant. */
    private function receiptThreshold(): ?float
    {
        try {
            $value = ExpensePolicy::forTenant($this->tenantId)
                ->where('enabled', true)
                ->whereNotNull('receipt_required_over')
                ->min('receipt_required_over');

            return $value !== null ? (float) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function budgetFindings(ExpenseReport $report): array
    {
        if (!method_exists(FinancialGovernor::class, 'checkBudgetLimit') || !Schema::hasTable('budgets')) {
            return [];
        }

        $findings = [];

        try {
            $sums = [];
            foreach ($report->items as $item) {
                $label = $item->category?->slug ?? $item->category?->name;
                if ($label === null) {
                    continue;
                }
                $sums[mb_strtolower($label)] = ($sums[mb_strtolower($label)] ?? 0) + (float) $item->amount;
            }

            if ($sums === []) {
                return [];
            }

            $governor = app(FinancialGovernor::class);

            $budgets = Budget::forTenant($this->tenantId)->active()->get();

            foreach ($budgets as $budget) {
                $label = mb_strtolower((string) $budget->category);
                if (!isset($sums[$label])) {
                    continue;
                }

                $check = $governor->checkBudgetLimit($this->tenantId, $budget->id, $sums[$label]);

                if (!($check['allowed'] ?? true)) {
                    $findings[] = [
                        'type' => 'budget_exceeded',
                        'budget_id' => $budget->id,
                        'category' => $budget->category,
                        'amount' => round($sums[$label], 2),
                        'utilization' => round((float) ($check['utilization'] ?? 0), 1),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('EvaluateSpendPolicyJob: budget check failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $findings;
    }

    private function createAlert(ExpenseReport $report, array $findings): void
    {
        if (!class_exists(FinancialAlert::class)) {
            return;
        }

        try {
            FinancialAlert::create([
                'tenant_id' => $this->tenantId,
                'type' => 'spend_policy_violation',
                'severity' => 'warning',
                'title' => 'Spend policy findings on '.$report->report_number,
                'message' => count($findings).' finding(s) detected on expense report '.$report->report_number.'.',
                'context' => [
                    'report_id' => $report->id,
                    'report_number' => $report->report_number,
                    'findings' => $findings,
                ],
                'status' => 'unread',
            ]);
        } catch (\Throwable $e) {
            Log::warning('EvaluateSpendPolicyJob: alert creation failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
