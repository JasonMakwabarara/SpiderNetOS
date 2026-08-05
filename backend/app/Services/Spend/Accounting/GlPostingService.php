<?php

declare(strict_types=1);

namespace App\Services\Spend\Accounting;

use App\Models\Bill;
use App\Models\ChartOfAccount;
use App\Models\ExpenseCategory;
use App\Models\ExpenseReport;
use App\Models\FinancialAccount;
use App\Models\FinancialAlert;
use App\Models\GlPosting;
use App\Models\SpendPostingRule;
use App\Services\EventStore;
use App\Services\Financial\LedgerService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Deterministic GL posting for approved spend (NO LLM anywhere in this path).
 *
 * Account resolution — how the pieces relate in this schema:
 *
 *   - `chart_of_accounts` is the reporting taxonomy (code + name + type);
 *     `financial_accounts` is the balance-carrying ledger dimension —
 *     LedgerService::postCompoundEntry debits/credits financial_accounts ids
 *     and stamps chart_account_id per line for per-category GL reporting.
 *   - expense_categories.gl_account_id / expense_items.gl_account_id /
 *     bill_line_items.gl_account_id reference chart_of_accounts.id (the
 *     mappings UI pairs categories with chart accounts). Because the ledger
 *     needs a financial_accounts id, each chart account is mirrored into a
 *     per-tenant FinancialAccount on first use, keyed on
 *     (tenant_id, account_number = chart code) — find-or-create, type copied
 *     from the chart account so normal-balance semantics hold.
 *   - spend_posting_rules credit columns store account CODES (string(20) —
 *     a uuid would not fit), resolved against financial_accounts.account_number
 *     first, then chart_of_accounts.code (mirrored as above). Unresolvable
 *     codes fail the posting with a recorded error.
 *
 * Exactly-once: gl_postings is unique on (tenant_id, source_type, source_id);
 * the row is written with insert-or-ignore and a 'posted' row is never
 * re-posted, so job retries and re-dispatches cannot double-post. Rows left
 * 'failed' or 'skipped' are recomputed on the next attempt (e.g. after the
 * admin fixes a missing mapping).
 */
class GlPostingService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly EventStore $eventStore,
        private readonly NotificationService $notifications,
    ) {}

    // ------------------------------------------------------------------ //
    //  Entry points
    // ------------------------------------------------------------------ //

    public function postExpenseReport(string $tenantId, string $reportId): ?GlPosting
    {
        $report = ExpenseReport::forTenant($tenantId)->with('items')->find($reportId);

        if (!$report || !in_array($report->status, ['approved', 'reimbursed'], true)) {
            return null;
        }

        $lines = $report->items->map(fn ($item) => [
            'amount' => (string) $item->amount,
            'category_id' => $item->category_id,
            'gl_account_id' => $item->gl_account_id,
            'description' => $item->description,
        ])->all();

        return $this->post(
            $tenantId,
            'expense_report',
            $report->id,
            $report->currency,
            "Expense report {$report->report_number}",
            $lines,
        );
    }

    public function postBill(string $tenantId, string $billId): ?GlPosting
    {
        $bill = Bill::forTenant($tenantId)->with('lineItems')->find($billId);

        if (!$bill || $bill->status !== 'paid') {
            return null;
        }

        // Tax handling: there is no tax account mapping in the schema, so the
        // per-line tax is folded into the line's debit (tax-inclusive expense)
        // — the debit total then equals the bill's total_amount / cash out.
        $lines = $bill->lineItems->map(function ($line) {
            $tax = bcmul((string) $line->total, bcdiv((string) $line->tax_rate, '100', 6), 4);

            return [
                'amount' => bcadd((string) $line->total, $tax, 4),
                'category_id' => $line->category_id,
                'gl_account_id' => $line->gl_account_id,
                'description' => $line->description,
            ];
        })->all();

        return $this->post(
            $tenantId,
            'bill',
            $bill->id,
            $bill->currency,
            "Bill {$bill->bill_number}",
            $lines,
        );
    }

    /**
     * Execute a 'draft' gl_postings row (the explicit POST endpoint).
     *
     * @throws \LogicException when the row is not in draft status
     */
    public function executeDraft(string $tenantId, string $postingId): GlPosting
    {
        return DB::transaction(function () use ($tenantId, $postingId) {
            $posting = GlPosting::forTenant($tenantId)->lockForUpdate()->findOrFail($postingId);

            if (!$posting->isDraft()) {
                throw new \LogicException("Only draft postings can be posted. Current status: {$posting->status}");
            }

            return $this->executePosting($posting);
        });
    }

    // ------------------------------------------------------------------ //
    //  Core pipeline
    // ------------------------------------------------------------------ //

    /**
     * @param array<int, array{amount: string, category_id: ?string, gl_account_id: ?string, description: ?string}> $sourceLines
     */
    private function post(
        string $tenantId,
        string $sourceType,
        string $sourceId,
        string $currency,
        string $description,
        array $sourceLines,
    ): ?GlPosting {
        $existing = GlPosting::forTenant($tenantId)->forSource($sourceType, $sourceId)->first();
        if ($existing && $existing->isPosted()) {
            return $existing; // Idempotent under retries.
        }

        if ($sourceLines === []) {
            return $existing;
        }

        $total = '0';
        foreach ($sourceLines as $line) {
            $total = bcadd($total, (string) $line['amount'], 4);
        }

        $rule = SpendPostingRule::forTenant($tenantId)->first();

        if (!$rule || !$rule->enabled) {
            return $this->recordSkipped(
                $tenantId, $sourceType, $sourceId, $total, $currency,
                'posting_rules_not_configured',
                'No enabled spend posting rules for this tenant.',
            );
        }

        $creditCode = $sourceType === 'bill'
            ? $rule->bill_credit_account_code
            : $rule->expense_credit_account_code;

        if ($creditCode === null || $creditCode === '') {
            return $this->recordSkipped(
                $tenantId, $sourceType, $sourceId, $total, $currency,
                'credit_account_not_configured',
                "No credit account code configured for {$sourceType} postings.",
            );
        }

        // --- Debit side: group source lines by chart account ------------- //
        $categories = ExpenseCategory::forTenant($tenantId)
            ->whereIn('id', array_filter(array_column($sourceLines, 'category_id')))
            ->pluck('gl_account_id', 'id');

        $grouped = [];       // chart_account_id => amount
        $unmapped = [];
        foreach ($sourceLines as $line) {
            $chartId = $line['gl_account_id']
                ?? ($line['category_id'] !== null ? $categories[$line['category_id']] ?? null : null);

            if ($chartId === null) {
                $unmapped[] = $line['description'] ?? 'line';
                continue;
            }

            $grouped[$chartId] = bcadd($grouped[$chartId] ?? '0', (string) $line['amount'], 4);
        }

        if ($unmapped !== []) {
            $posting = $this->recordSkipped(
                $tenantId, $sourceType, $sourceId, $total, $currency,
                'gl_mapping_missing',
                'Lines without a GL account mapping: '.implode(', ', array_slice($unmapped, 0, 5)),
            );
            $this->raiseMappingAlert($tenantId, $sourceType, $sourceId, $description, $unmapped);

            return $posting;
        }

        // --- Resolve chart accounts to financial accounts ---------------- //
        $chartAccounts = ChartOfAccount::where('tenant_id', $tenantId)
            ->whereIn('id', array_keys($grouped))
            ->get()
            ->keyBy('id');

        $debitLines = [];
        foreach ($grouped as $chartId => $amount) {
            $chart = $chartAccounts[$chartId] ?? null;
            if (!$chart) {
                $posting = $this->recordSkipped(
                    $tenantId, $sourceType, $sourceId, $total, $currency,
                    'gl_mapping_missing',
                    "Mapped chart account {$chartId} does not exist for this tenant.",
                );
                $this->raiseMappingAlert($tenantId, $sourceType, $sourceId, $description, [$chartId]);

                return $posting;
            }

            $debitLines[] = [
                'account_id' => $this->mirrorAccount($tenantId, $chart)->id,
                'chart_account_id' => $chart->id,
                'amount' => $amount,
                'description' => "{$description} — {$chart->name}",
            ];
        }

        // --- Resolve credit account -------------------------------------- //
        $creditAccount = $this->resolveAccountByCode($tenantId, $creditCode);
        if (!$creditAccount) {
            return $this->recordFailure(
                $tenantId, $sourceType, $sourceId, $total, $currency, [],
                "Credit account code '{$creditCode}' matches no financial or chart account.",
            );
        }

        $lines = [
            'debit_lines' => $debitLines,
            'credit_account_id' => $creditAccount->id,
            'credit_account_code' => $creditCode,
            'description' => $description,
        ];

        $posting = $this->upsertRow($tenantId, $sourceType, $sourceId, [
            'status' => 'draft',
            'amount' => $total,
            'currency' => $currency,
            'lines' => $lines,
            'error' => null,
        ]);

        if ($posting->isPosted()) {
            return $posting; // Concurrent worker won the race.
        }

        if (!$rule->isAuto()) {
            // Draft mode: the journal is recorded; an admin executes it via
            // POST /accounting/postings/{id}/post.
            $this->eventStore->append($tenantId, 'gl_posting', $posting->id, 'gl.posting_created', [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'status' => 'draft',
                'amount' => $total,
                'currency' => $currency,
            ]);

            return $posting;
        }

        return $this->executePosting($posting);
    }

    /** Post a draft row's stored journal to the ledger. */
    private function executePosting(GlPosting $posting): GlPosting
    {
        $lines = $posting->lines ?? [];

        try {
            $transaction = $this->ledger->postCompoundEntry(
                $posting->tenant_id,
                $lines['debit_lines'] ?? [],
                (string) $lines['credit_account_id'],
                $posting->currency,
                (string) ($lines['description'] ?? "GL posting {$posting->id}"),
                $posting->source_type,
                $posting->source_id,
            );

            $posting->update([
                'status' => 'posted',
                'transaction_number' => $transaction->transaction_number,
                'posted_at' => now(),
                'error' => null,
            ]);

            $this->eventStore->append($posting->tenant_id, 'gl_posting', $posting->id, 'gl.posting_created', [
                'source_type' => $posting->source_type,
                'source_id' => $posting->source_id,
                'status' => 'posted',
                'transaction_number' => $transaction->transaction_number,
                'amount' => (string) $posting->amount,
                'currency' => $posting->currency,
            ]);

            return $posting->fresh();
        } catch (\Throwable $e) {
            $posting->update(['status' => 'failed', 'error' => $e->getMessage()]);

            $this->eventStore->append($posting->tenant_id, 'gl_posting', $posting->id, 'gl.posting_failed', [
                'source_type' => $posting->source_type,
                'source_id' => $posting->source_id,
                'error' => $e->getMessage(),
            ]);

            Log::warning('GlPostingService: ledger post failed', [
                'posting' => $posting->id,
                'error' => $e->getMessage(),
            ]);

            return $posting->fresh();
        }
    }

    // ------------------------------------------------------------------ //
    //  Account resolution
    // ------------------------------------------------------------------ //

    /**
     * Per-tenant FinancialAccount mirror of a chart account, keyed on
     * (tenant_id, account_number = chart code). See class docblock.
     */
    private function mirrorAccount(string $tenantId, ChartOfAccount $chart): FinancialAccount
    {
        $existing = FinancialAccount::where('tenant_id', $tenantId)
            ->where('account_number', $chart->code)
            ->first();

        if ($existing) {
            return $existing;
        }

        return FinancialAccount::create([
            'tenant_id' => $tenantId,
            'name' => $chart->name,
            'account_number' => $chart->code,
            'type' => $chart->type ?: 'expense',
            'currency' => 'USD',
            'status' => 'active',
            'opened_at' => now(),
            'metadata' => ['mirror_of_chart_account_id' => $chart->id],
        ]);
    }

    private function resolveAccountByCode(string $tenantId, string $code): ?FinancialAccount
    {
        $account = FinancialAccount::where('tenant_id', $tenantId)
            ->where('account_number', $code)
            ->first();

        if ($account) {
            return $account;
        }

        $chart = ChartOfAccount::where('tenant_id', $tenantId)->where('code', $code)->first();

        return $chart ? $this->mirrorAccount($tenantId, $chart) : null;
    }

    // ------------------------------------------------------------------ //
    //  Row bookkeeping
    // ------------------------------------------------------------------ //

    /**
     * Insert-or-ignore on the (tenant_id, source_type, source_id) unique key,
     * then update the surviving row unless it is already posted.
     */
    private function upsertRow(string $tenantId, string $sourceType, string $sourceId, array $attributes): GlPosting
    {
        $now = now();

        $inserted = DB::table('gl_postings')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'status' => $attributes['status'],
            'amount' => $attributes['amount'],
            'currency' => $attributes['currency'],
            'lines' => json_encode($attributes['lines']),
            'error' => $attributes['error'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $posting = GlPosting::forTenant($tenantId)->forSource($sourceType, $sourceId)->first();

        if ($inserted === 0 && !$posting->isPosted()) {
            // Pre-existing (failed/skipped/draft) row — recompute it.
            $posting->update($attributes);
            $posting = $posting->fresh();
        }

        return $posting;
    }

    private function recordSkipped(
        string $tenantId,
        string $sourceType,
        string $sourceId,
        string $amount,
        string $currency,
        string $reason,
        string $detail,
    ): GlPosting {
        $posting = $this->upsertRow($tenantId, $sourceType, $sourceId, [
            'status' => 'skipped',
            'amount' => $amount,
            'currency' => $currency,
            'lines' => ['skip_reason' => $reason],
            'error' => $detail,
        ]);

        if (!$posting->isPosted()) {
            $this->eventStore->append($tenantId, 'gl_posting', $posting->id, 'gl.posting_failed', [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'status' => 'skipped',
                'reason' => $reason,
                'detail' => $detail,
            ]);
        }

        return $posting;
    }

    private function recordFailure(
        string $tenantId,
        string $sourceType,
        string $sourceId,
        string $amount,
        string $currency,
        array $lines,
        string $error,
    ): GlPosting {
        $posting = $this->upsertRow($tenantId, $sourceType, $sourceId, [
            'status' => 'failed',
            'amount' => $amount,
            'currency' => $currency,
            'lines' => $lines,
            'error' => $error,
        ]);

        if (!$posting->isPosted()) {
            $this->eventStore->append($tenantId, 'gl_posting', $posting->id, 'gl.posting_failed', [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'status' => 'failed',
                'error' => $error,
            ]);
        }

        return $posting;
    }

    private function raiseMappingAlert(
        string $tenantId,
        string $sourceType,
        string $sourceId,
        string $description,
        array $unmapped,
    ): void {
        try {
            FinancialAlert::create([
                'tenant_id' => $tenantId,
                'type' => 'gl_mapping_missing',
                'severity' => 'warning',
                'title' => 'GL posting skipped — mapping missing',
                'message' => "{$description} could not be posted to the general ledger: "
                    .count($unmapped).' line(s) have no GL account mapping.',
                'context' => [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'unmapped' => array_slice(array_values($unmapped), 0, 10),
                ],
                'status' => 'unread',
            ]);
        } catch (\Throwable $e) {
            Log::warning('GlPostingService: alert creation failed', ['error' => $e->getMessage()]);
        }

        try {
            $this->notifications->notifyTenantRole($tenantId, ['admin', 'super_admin'], 'gl_mapping_missing', [
                'title' => 'GL mapping missing',
                'body' => "{$description} was not posted to the ledger — map its categories to GL accounts.",
                'url' => '/spend/accounting/mappings',
            ]);
        } catch (\Throwable $e) {
            Log::warning('GlPostingService: mapping notification failed', ['error' => $e->getMessage()]);
        }
    }
}
