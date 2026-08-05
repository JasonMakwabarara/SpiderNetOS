<?php

namespace App\Http\Controllers\Spend;

use App\Http\Controllers\Controller;
use App\Models\AccountingExport;
use App\Models\Bill;
use App\Models\Budget;
use App\Models\ChartOfAccount;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\GlPosting;
use App\Models\SpendExportSchedule;
use App\Models\SpendPostingRule;
use App\Services\Spend\Accounting\AccountingExportService;
use App\Services\Spend\Accounting\GlPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stage 3 — accounting automation: category -> GL mappings, posting rules,
 * GL postings (draft execution), exports + schedules, and the spend
 * analytics summary.
 */
class AccountingController extends Controller
{
    public function __construct(
        private readonly GlPostingService $postings,
        private readonly AccountingExportService $exports,
    ) {}

    // ------------------------------------------------------------------ //
    //  Mappings
    // ------------------------------------------------------------------ //

    public function mappings(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        return response()->json(['data' => [
            'categories' => ExpenseCategory::forTenant($tenant->id)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'gl_account_id', 'active']),
            'chart_accounts' => ChartOfAccount::where('tenant_id', $tenant->id)
                ->active()
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type']),
        ]]);
    }

    public function updateMappings(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'mappings' => 'required|array|min:1',
            'mappings.*.category_id' => 'required|uuid',
            'mappings.*.gl_account_id' => 'present|nullable|uuid',
        ]);

        $chartIds = ChartOfAccount::where('tenant_id', $tenant->id)->pluck('id')->all();

        foreach ($validated['mappings'] as $mapping) {
            if ($mapping['gl_account_id'] !== null && !in_array($mapping['gl_account_id'], $chartIds, true)) {
                return response()->json([
                    'error' => "Chart account {$mapping['gl_account_id']} does not belong to this tenant.",
                ], 422);
            }

            ExpenseCategory::forTenant($tenant->id)
                ->where('id', $mapping['category_id'])
                ->update(['gl_account_id' => $mapping['gl_account_id']]);
        }

        return response()->json(['data' => ExpenseCategory::forTenant($tenant->id)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'gl_account_id', 'active'])]);
    }

    // ------------------------------------------------------------------ //
    //  Posting rules
    // ------------------------------------------------------------------ //

    public function rules(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $rule = SpendPostingRule::forTenant($tenant->id)->first();

        return response()->json(['data' => $rule ?? [
            'tenant_id' => $tenant->id,
            'mode' => 'draft',
            'expense_credit_account_code' => null,
            'bill_credit_account_code' => null,
            'enabled' => true,
        ]]);
    }

    public function updateRules(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'mode' => 'sometimes|in:auto,draft',
            'expense_credit_account_code' => 'sometimes|nullable|string|max:20',
            'bill_credit_account_code' => 'sometimes|nullable|string|max:20',
            'enabled' => 'sometimes|boolean',
        ]);

        $rule = SpendPostingRule::updateOrCreate(
            ['tenant_id' => $tenant->id],
            $validated,
        );

        return response()->json(['data' => $rule->fresh()]);
    }

    // ------------------------------------------------------------------ //
    //  Postings
    // ------------------------------------------------------------------ //

    public function postings(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $query = GlPosting::forTenant($tenant->id);

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        return response()->json([
            'data' => $query->orderByDesc('created_at')->paginate($request->get('per_page', 20)),
        ]);
    }

    /** Execute a draft posting against the ledger. */
    public function executePosting(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        try {
            $posting = $this->postings->executeDraft($tenant->id, $id);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $posting]);
    }

    // ------------------------------------------------------------------ //
    //  Exports
    // ------------------------------------------------------------------ //

    public function exports(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        return response()->json([
            'data' => AccountingExport::forTenant($tenant->id)
                ->orderByDesc('created_at')
                ->paginate($request->get('per_page', 20)),
        ]);
    }

    public function createExport(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'export_type' => 'required|in:quickbooks_csv,xero_csv,generic_csv',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
        ]);

        $export = $this->exports->generate(
            $tenant->id,
            $validated['export_type'],
            Carbon::parse($validated['period_start'])->toDateString(),
            Carbon::parse($validated['period_end'])->toDateString(),
            $request->user()->id,
        );

        return response()->json(['data' => $export], 201);
    }

    public function downloadExport(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $export = AccountingExport::forTenant($tenant->id)->findOrFail($id);

        try {
            return $this->exports->streamDownload($export);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }
    }

    // ------------------------------------------------------------------ //
    //  Export schedules
    // ------------------------------------------------------------------ //

    public function schedules(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        return response()->json([
            'data' => SpendExportSchedule::forTenant($tenant->id)->orderBy('created_at')->get(),
        ]);
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $this->validateSchedule($request, required: true);

        $schedule = SpendExportSchedule::create($validated + ['tenant_id' => $tenant->id]);

        return response()->json(['data' => $schedule], 201);
    }

    public function updateSchedule(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $schedule = SpendExportSchedule::forTenant($tenant->id)->findOrFail($id);
        $schedule->update($this->validateSchedule($request, required: false));

        return response()->json(['data' => $schedule->fresh()]);
    }

    public function destroySchedule(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        SpendExportSchedule::forTenant($tenant->id)->findOrFail($id)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function validateSchedule(Request $request, bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return $request->validate([
            'export_type' => "{$presence}|in:quickbooks_csv,xero_csv,generic_csv",
            'scope' => 'sometimes|string|max:20',
            'frequency' => "{$presence}|in:weekly,monthly",
            'delivery' => 'sometimes|in:notification,webhook',
            'destination' => 'sometimes|nullable|array',
            'destination.url' => 'sometimes|url',
            'enabled' => 'sometimes|boolean',
        ]);
    }

    // ------------------------------------------------------------------ //
    //  Spend analytics summary
    // ------------------------------------------------------------------ //

    /**
     * GET /spend/summary?group_by=category|vendor&period=30d|90d|12m
     *
     * series           — time-bucketed totals (day for 30d/90d, month for 12m)
     * groups           — totals per category/vendor over the period
     * budget_vs_actual — active budgets with a category matched against the
     *                    period's actuals by category slug/name.
     */
    public function spendSummary(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'group_by' => 'sometimes|in:category,vendor',
            'period' => 'sometimes|in:30d,90d,12m',
        ]);

        $groupBy = $validated['group_by'] ?? 'category';
        $period = $validated['period'] ?? '30d';

        $end = now()->endOfDay();
        $start = match ($period) {
            '30d' => now()->subDays(30)->startOfDay(),
            '90d' => now()->subDays(90)->startOfDay(),
            '12m' => now()->subMonths(12)->startOfDay(),
        };
        $bucketFormat = $period === '12m' ? 'Y-m' : 'Y-m-d';

        $categoryNames = ExpenseCategory::forTenant($tenant->id)->get(['id', 'name', 'slug']);
        $nameById = $categoryNames->pluck('name', 'id');

        $series = [];   // bucket => total
        $groups = [];   // label => total
        $actualByCategory = []; // category name => total (for budget matching)

        $liveReportStatuses = ['submitted', 'awaiting_approval', 'approved', 'reimbursed'];

        $items = ExpenseItem::forTenant($tenant->id)
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('report', fn ($q) => $q->whereIn('status', $liveReportStatuses))
            ->get(['category_id', 'merchant', 'amount', 'expense_date']);

        foreach ($items as $item) {
            $bucket = $item->expense_date->format($bucketFormat);
            $series[$bucket] = bcadd($series[$bucket] ?? '0', (string) $item->amount, 4);

            $category = $item->category_id !== null
                ? ($nameById[$item->category_id] ?? 'Uncategorized')
                : 'Uncategorized';
            $actualByCategory[$category] = bcadd($actualByCategory[$category] ?? '0', (string) $item->amount, 4);

            $label = $groupBy === 'category' ? $category : ($item->merchant ?: 'Unknown');
            $groups[$label] = bcadd($groups[$label] ?? '0', (string) $item->amount, 4);
        }

        $bills = Bill::forTenant($tenant->id)
            ->whereNot('status', 'void')
            ->whereBetween('created_at', [$start, $end])
            ->with(['lineItems', 'vendor'])
            ->get();

        foreach ($bills as $bill) {
            $bucket = $bill->created_at->format($bucketFormat);
            $series[$bucket] = bcadd($series[$bucket] ?? '0', (string) $bill->total_amount, 4);

            if ($groupBy === 'vendor') {
                $label = $bill->vendor?->name ?: 'Unknown';
                $groups[$label] = bcadd($groups[$label] ?? '0', (string) $bill->total_amount, 4);
            }

            foreach ($bill->lineItems as $line) {
                $category = $line->category_id !== null
                    ? ($nameById[$line->category_id] ?? 'Uncategorized')
                    : 'Uncategorized';
                $actualByCategory[$category] = bcadd($actualByCategory[$category] ?? '0', (string) $line->total, 4);

                if ($groupBy === 'category') {
                    $groups[$category] = bcadd($groups[$category] ?? '0', (string) $line->total, 4);
                }
            }
        }

        ksort($series);
        arsort($groups);

        // Budget-vs-actual: budgets carry a free-form category string matched
        // case-insensitively against category slug or name.
        $budgetVsActual = Budget::forTenant($tenant->id)
            ->active()
            ->whereNotNull('category')
            ->get()
            ->map(function (Budget $budget) use ($categoryNames, $actualByCategory) {
                $match = $categoryNames->first(fn ($c) => strcasecmp($c->slug, $budget->category) === 0
                    || strcasecmp($c->name, $budget->category) === 0);

                $actual = $match !== null
                    ? ($actualByCategory[$match->name] ?? '0')
                    : ($actualByCategory[$budget->category] ?? '0');

                return [
                    'budget_id' => $budget->id,
                    'name' => $budget->name,
                    'category' => $budget->category,
                    'budget' => (string) $budget->amount,
                    'actual' => $actual,
                    'utilization_pct' => bccomp((string) $budget->amount, '0', 4) > 0
                        ? (float) bcmul(bcdiv($actual, (string) $budget->amount, 6), '100', 1)
                        : null,
                ];
            })
            ->values();

        return response()->json(['data' => [
            'group_by' => $groupBy,
            'period' => $period,
            'series' => collect($series)
                ->map(fn ($total, $bucket) => ['bucket' => $bucket, 'total' => $total])
                ->values(),
            'groups' => collect($groups)
                ->map(fn ($total, $key) => ['key' => $key, 'total' => $total])
                ->values(),
            'budget_vs_actual' => $budgetVsActual,
        ]]);
    }
}
