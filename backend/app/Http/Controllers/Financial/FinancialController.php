<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\FinancialAlert;
use App\Models\FinancialReport;
use App\Models\TaxRate;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Financial\FinancialGovernor;
use App\Services\Financial\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialController extends Controller
{
    public function __construct(
        private readonly ReportingService $reportingService,
        private readonly FinancialGovernor $governor,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $metrics = $this->reportingService->getDashboardMetrics($tenant->id);

        return response()->json(['data' => $metrics]);
    }

    public function generateReport(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'type' => 'required|in:profit_and_loss,balance_sheet,cash_flow',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $report = match ($validated['type']) {
            'profit_and_loss' => $this->reportingService->generateProfitAndLoss(
                $tenant->id, $validated['start_date'], $validated['end_date']
            ),
            'balance_sheet' => $this->reportingService->generateBalanceSheet(
                $tenant->id, $validated['end_date']
            ),
            'cash_flow' => $this->reportingService->generateCashFlowStatement(
                $tenant->id, $validated['start_date'], $validated['end_date']
            ),
        };

        return response()->json(['data' => $report], 201);
    }

    public function reports(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $reports = FinancialReport::forTenant($tenant->id)
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 20));

        return response()->json(['data' => $reports]);
    }

    public function wallets(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $wallets = Wallet::forTenant($tenant->id)->get();

        return response()->json(['data' => $wallets]);
    }

    public function createWallet(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'type' => 'required|in:fiat,crypto',
            'currency' => 'required|string|size:3',
        ]);

        $wallet = Wallet::create([
            'tenant_id' => $tenant->id,
            'type' => $validated['type'],
            'currency' => $validated['currency'],
        ]);

        return response()->json(['data' => $wallet], 201);
    }

    public function walletTransactions(Request $request, string $walletId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $transactions = WalletTransaction::forTenant($tenant->id)
            ->where('wallet_id', $walletId)
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 20));

        return response()->json(['data' => $transactions]);
    }

    public function budgets(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $budgets = Budget::forTenant($tenant->id)->paginate($request->get('per_page', 20));

        return response()->json(['data' => $budgets]);
    }

    public function createBudget(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'name' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'category' => 'sometimes|string',
            'currency' => 'sometimes|string|size:3',
            'period' => 'sometimes|in:weekly,monthly,quarterly,yearly',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'alert_threshold' => 'sometimes|numeric|min:0|max:100',
        ]);

        $budget = Budget::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'amount' => $validated['amount'],
            'category' => $validated['category'] ?? null,
            'currency' => $validated['currency'] ?? 'USD',
            'period' => $validated['period'] ?? 'monthly',
            'period_start' => $validated['period_start'],
            'period_end' => $validated['period_end'],
            'alert_threshold' => $validated['alert_threshold'] ?? 80,
        ]);

        return response()->json(['data' => $budget], 201);
    }

    public function taxRates(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $taxRates = TaxRate::where('tenant_id', $tenant->id)->active()->get();

        return response()->json(['data' => $taxRates]);
    }

    public function createTaxRate(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'name' => 'required|string',
            'rate' => 'required|numeric|min:0|max:100',
            'jurisdiction' => 'sometimes|string',
            'type' => 'sometimes|in:percentage,fixed',
            'code' => 'sometimes|string',
        ]);

        $taxRate = TaxRate::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'rate' => $validated['rate'],
            'jurisdiction' => $validated['jurisdiction'] ?? null,
            'type' => $validated['type'] ?? 'percentage',
            'code' => $validated['code'] ?? null,
        ]);

        return response()->json(['data' => $taxRate], 201);
    }

    public function alerts(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $alerts = FinancialAlert::forTenant($tenant->id)
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 20));

        return response()->json(['data' => $alerts]);
    }

    public function acknowledgeAlert(Request $request, string $alertId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $alert = FinancialAlert::forTenant($tenant->id)->findOrFail($alertId);
        $alert->markAcknowledged();

        return response()->json(['data' => $alert]);
    }

    public function agingReport(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $aging = $this->reportingService->getAgingReport($tenant->id);

        return response()->json(['data' => $aging]);
    }

    public function checkTransactionRisk(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'transaction_id' => 'required|string',
            'amount' => 'required|numeric|min:0',
            'method' => 'required|string',
            'counterparty_email' => 'sometimes|email',
        ]);

        $result = $this->governor->detectFraudulentTransaction(
            $tenant->id,
            $validated['transaction_id'],
            $validated['amount'],
            $validated['method'],
            $validated['counterparty_email'] ?? null,
        );

        return response()->json(['data' => $result]);
    }
}
