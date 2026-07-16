<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\ChartOfAccount;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Services\Financial\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledgerService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        
        $entries = LedgerEntry::forTenant($tenant->id)
            ->with('account')
            ->orderByDesc('posted_at')
            ->paginate($request->get('per_page', 20));

        return response()->json(['data' => $entries]);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        
        $result = $this->ledgerService->getTrialBalance(
            $tenant->id,
            $request->get('start_date'),
            $request->get('end_date'),
        );

        return response()->json(['data' => $result]);
    }

    public function generalLedger(Request $request, string $accountId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        
        $entries = $this->ledgerService->getGeneralLedger(
            $tenant->id,
            $accountId,
            $request->get('start_date'),
            $request->get('end_date'),
        );

        return response()->json(['data' => $entries]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        
        $result = $this->ledgerService->getCashFlow(
            $tenant->id,
            $request->get('start_date', now()->copy()->startOfMonth()->toDateString()),
            $request->get('end_date', now()->toDateString()),
        );

        return response()->json(['data' => $result]);
    }

    public function journalEntry(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'source_account_id' => 'required|uuid|exists:financial_accounts,id',
            'destination_account_id' => 'required|uuid|exists:financial_accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'sometimes|string|size:3',
            'description' => 'sometimes|string',
            'reference_type' => 'sometimes|string',
            'reference_id' => 'sometimes|string',
        ]);

        $transaction = $this->ledgerService->createJournalEntry(
            $tenant->id,
            $validated['source_account_id'],
            $validated['destination_account_id'],
            $validated['amount'],
            $validated['currency'] ?? 'USD',
            $validated['description'] ?? '',
            $validated['reference_type'] ?? null,
            $validated['reference_id'] ?? null,
        );

        return response()->json(['data' => $transaction], 201);
    }

    public function accounts(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        
        $accounts = FinancialAccount::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $accounts]);
    }

    public function createAccount(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'name' => 'required|string',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'category' => 'sometimes|string',
            'currency' => 'sometimes|string|size:3',
        ]);

        $account = FinancialAccount::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'category' => $validated['category'] ?? null,
            'currency' => $validated['currency'] ?? 'USD',
            'opened_at' => now(),
        ]);

        return response()->json(['data' => $account], 201);
    }

    public function chartOfAccounts(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        
        $accounts = ChartOfAccount::where('tenant_id', $tenant->id)
            ->active()
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $accounts]);
    }

    public function createChartAccount(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'description' => 'sometimes|string',
            'parent_code' => 'sometimes|string',
        ]);

        $account = ChartOfAccount::create([
            'tenant_id' => $tenant->id,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'type' => $validated['type'],
            'description' => $validated['description'] ?? null,
            'parent_code' => $validated['parent_code'] ?? null,
        ]);

        return response()->json(['data' => $account], 201);
    }
}
