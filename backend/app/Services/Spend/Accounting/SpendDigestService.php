<?php

declare(strict_types=1);

namespace App\Services\Spend\Accounting;

use App\Models\Bill;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic weekly spend digest. Everything here is plain SQL/stats —
 * the optional single LLM "insight" sentence sits behind
 * config('spend.digest_llm_insight') and always falls back to the
 * deterministic sentence when disabled or unreachable.
 */
class SpendDigestService
{
    /**
     * @return array{
     *   period: array{start: string, end: string},
     *   total: string, currency: string,
     *   by_category: array<int, array{category: string, total: string}>,
     *   top_merchants: array<int, array{merchant: string, total: string}>,
     *   pending_approvals: int,
     *   policy_flag_count: int,
     *   insight: string,
     * }
     */
    public function buildDigest(string $tenantId, Carbon $start, Carbon $end): array
    {
        $categoryNames = ExpenseCategory::forTenant($tenantId)->pluck('name', 'id');

        // --- Expense items in period (non-dead reports only) -------------- //
        $items = ExpenseItem::forTenant($tenantId)
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('report', fn ($q) => $q->whereIn('status', [
                'submitted', 'awaiting_approval', 'approved', 'reimbursed',
            ]))
            ->get(['category_id', 'merchant', 'amount', 'policy_flags']);

        $byCategory = [];
        $byMerchant = [];
        $policyFlagCount = 0;

        foreach ($items as $item) {
            $label = $item->category_id !== null
                ? ($categoryNames[$item->category_id] ?? 'Uncategorized')
                : 'Uncategorized';
            $byCategory[$label] = bcadd($byCategory[$label] ?? '0', (string) $item->amount, 4);

            if ($item->merchant) {
                $byMerchant[$item->merchant] = bcadd($byMerchant[$item->merchant] ?? '0', (string) $item->amount, 4);
            }

            if (!empty($item->policy_flags)) {
                $policyFlagCount++;
            }
        }

        // --- Bills in period (grouped by line category / vendor) ---------- //
        $bills = Bill::forTenant($tenantId)
            ->whereNot('status', 'void')
            ->whereBetween('created_at', [$start, $end->copy()->endOfDay()])
            ->with(['lineItems', 'vendor'])
            ->get();

        foreach ($bills as $bill) {
            foreach ($bill->lineItems as $line) {
                $label = $line->category_id !== null
                    ? ($categoryNames[$line->category_id] ?? 'Uncategorized')
                    : 'Uncategorized';
                $byCategory[$label] = bcadd($byCategory[$label] ?? '0', (string) $line->total, 4);
            }

            if ($bill->vendor?->name) {
                $byMerchant[$bill->vendor->name] = bcadd(
                    $byMerchant[$bill->vendor->name] ?? '0',
                    (string) $bill->total_amount,
                    4,
                );
            }
        }

        arsort($byCategory);
        arsort($byMerchant);

        $total = '0';
        foreach ($byCategory as $amount) {
            $total = bcadd($total, $amount, 4);
        }

        $pendingApprovals = (int) DB::table('approvals')
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->whereIn('resource_type', ['expense_report', 'bill'])
            ->count();

        $digest = [
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'total' => $total,
            'currency' => 'USD',
            'by_category' => collect($byCategory)
                ->map(fn ($amount, $category) => ['category' => $category, 'total' => $amount])
                ->values()
                ->all(),
            'top_merchants' => collect($byMerchant)
                ->take(5)
                ->map(fn ($amount, $merchant) => ['merchant' => $merchant, 'total' => $amount])
                ->values()
                ->all(),
            'pending_approvals' => $pendingApprovals,
            'policy_flag_count' => $policyFlagCount,
        ];

        $digest['insight'] = $this->insight($tenantId, $digest);

        return $digest;
    }

    public function summaryLine(array $digest): string
    {
        $categories = collect($digest['by_category'])
            ->take(3)
            ->map(fn ($row) => sprintf('%s (%s)', $row['category'], $this->money($row['total'])))
            ->implode(', ');

        return sprintf(
            'Spend %s to %s: %s%s. Pending approvals: %d. Policy flags: %d.',
            $digest['period']['start'],
            $digest['period']['end'],
            $this->money($digest['total']),
            $categories !== '' ? ' — top categories: '.$categories : '',
            $digest['pending_approvals'],
            $digest['policy_flag_count'],
        );
    }

    /**
     * Plain-stats insight sentence; a single optional LLM call sits behind
     * config('spend.digest_llm_insight', false) and falls back on any error.
     */
    private function insight(string $tenantId, array $digest): string
    {
        $fallback = $this->deterministicInsight($digest);

        if (!config('spend.digest_llm_insight', false)) {
            return $fallback;
        }

        $inferenceUrl = (string) config('services.inference.url', '');
        if ($inferenceUrl === '') {
            return $fallback;
        }

        try {
            $response = Http::timeout(8)
                ->post(rtrim($inferenceUrl, '/').'/v1/generate', [
                    'tenant_id' => $tenantId,
                    'task' => 'spend_digest_insight',
                    'prompt' => 'One factual sentence about this weekly spend digest: '
                        .json_encode(collect($digest)->except('insight')->all()),
                    'max_tokens' => 80,
                ]);

            $text = trim((string) $response->json('text', ''));

            return $response->successful() && $text !== '' ? $text : $fallback;
        } catch (\Throwable $e) {
            Log::info('SpendDigestService: insight call failed, using stats fallback', [
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    private function deterministicInsight(array $digest): string
    {
        if (bccomp($digest['total'], '0', 4) <= 0) {
            return 'No recorded spend this period.';
        }

        $top = $digest['by_category'][0] ?? null;
        if ($top === null) {
            return sprintf('Total spend was %s this period.', $this->money($digest['total']));
        }

        $share = bcmul(bcdiv($top['total'], $digest['total'], 6), '100', 1);

        return sprintf(
            '%s was the largest category at %s (%s%% of %s total spend).',
            $top['category'],
            $this->money($top['total']),
            $share,
            $this->money($digest['total']),
        );
    }

    private function money(string $amount): string
    {
        return 'USD '.number_format((float) $amount, 2);
    }
}
