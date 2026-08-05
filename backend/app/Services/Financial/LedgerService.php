<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\LedgerEntry;
use App\Models\FinancialAccount;
use App\Models\ChartOfAccount;
use App\Models\Transaction;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LedgerService
{
    /**
     * Account types whose balance increases on the DEBIT side.
     * All other types (liability, equity, revenue, income) are credit-normal.
     */
    private const DEBIT_NORMAL_TYPES = ['asset', 'expense', 'investment'];

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    public function createJournalEntry(
        string $tenantId,
        string $sourceAccountId,
        string $destinationAccountId,
        string $amount,
        string $currency = 'USD',
        string $description = '',
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): Transaction {
        return DB::transaction(function () use (
            $tenantId, $sourceAccountId, $destinationAccountId,
            $amount, $currency, $description, $referenceType, $referenceId
        ) {
            $transactionNumber = $this->generateTransactionNumber($tenantId);

            $transaction = Transaction::create([
                'tenant_id' => $tenantId,
                'transaction_number' => $transactionNumber,
                'type' => 'journal_entry',
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'completed',
                'description' => $description,
                'source_account_id' => $sourceAccountId,
                'destination_account_id' => $destinationAccountId,
                'initiated_at' => now(),
                'completed_at' => now(),
            ]);

            $this->createDoubleEntry(
                tenantId: $tenantId,
                transactionNumber: $transactionNumber,
                sourceAccountId: $sourceAccountId,
                destinationAccountId: $destinationAccountId,
                amount: $amount,
                currency: $currency,
                description: $description,
                referenceType: $referenceType,
                referenceId: $referenceId,
            );

            $this->eventStore->append(
                $tenantId,
                'ledger',
                $transaction->id,
                'ledger.entry_posted',
                [
                    'transaction_id' => $transactionNumber,
                    'source_account_id' => $sourceAccountId,
                    'destination_account_id' => $destinationAccountId,
                    'amount' => $amount,
                    'currency' => $currency,
                ]
            );

            $this->updateAccountBalances($tenantId, $sourceAccountId, $destinationAccountId, $amount);

            return $transaction;
        });
    }

    public function createDoubleEntry(
        string $tenantId,
        string $transactionNumber,
        string $sourceAccountId,
        string $destinationAccountId,
        string $amount,
        string $currency,
        string $description = '',
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): void {
        LedgerEntry::create([
            'tenant_id' => $tenantId,
            'account_id' => $sourceAccountId,
            'transaction_id' => $transactionNumber,
            'entry_type' => 'journal',
            'side' => 'credit',
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'posted_at' => now(),
        ]);

        LedgerEntry::create([
            'tenant_id' => $tenantId,
            'account_id' => $destinationAccountId,
            'transaction_id' => $transactionNumber,
            'entry_type' => 'journal',
            'side' => 'debit',
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'posted_at' => now(),
        ]);
    }

    public function getAccountBalance(string $accountId): float
    {
        $account = FinancialAccount::findOrFail($accountId);
        return (float) $account->balance;
    }

    public function getTrialBalance(string $tenantId, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = LedgerEntry::forTenant($tenantId);

        if ($startDate) {
            $query->where('posted_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('posted_at', '<=', $endDate);
        }

        $entries = $query->get();

        $balances = [];
        foreach ($entries as $entry) {
            if (!isset($balances[$entry->account_id])) {
                $balances[$entry->account_id] = [
                    'account_id' => $entry->account_id,
                    'account_name' => $entry->account->name ?? 'Unknown',
                    'debits' => 0,
                    'credits' => 0,
                    'net' => 0,
                ];
            }

            if ($entry->side === 'debit') {
                $balances[$entry->account_id]['debits'] += (float) $entry->amount;
            } else {
                $balances[$entry->account_id]['credits'] += (float) $entry->amount;
            }
        }

        foreach ($balances as &$balance) {
            $balance['net'] = $balance['debits'] - $balance['credits'];
        }

        return $balances;
    }

    public function getGeneralLedger(string $tenantId, string $accountId, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = LedgerEntry::forTenant($tenantId)
            ->where('account_id', $accountId)
            ->with('account')
            ->orderBy('posted_at');

        if ($startDate) $query->where('posted_at', '>=', $startDate);
        if ($endDate) $query->where('posted_at', '<=', $endDate);

        return $query->get()->toArray();
    }

    public function getCashFlow(string $tenantId, string $startDate, string $endDate): array
    {
        $entries = LedgerEntry::forTenant($tenantId)
            ->forDateRange($startDate, $endDate)
            ->with('account')
            ->orderBy('posted_at')
            ->get();

        $operatingActivities = [];
        $investingActivities = [];
        $financingActivities = [];

        foreach ($entries as $entry) {
            $item = [
                'date' => $entry->posted_at,
                'description' => $entry->description,
                'amount' => $entry->side === 'debit' ? (float) $entry->amount : -(float) $entry->amount,
            ];

            $type = $entry->account->type ?? '';
            if (in_array($type, ['asset', 'liability', 'equity'])) {
                $operatingActivities[] = $item;
            } elseif ($type === 'investment') {
                $investingActivities[] = $item;
            } else {
                $financingActivities[] = $item;
            }
        }

        return [
            'operating' => $operatingActivities,
            'investing' => $investingActivities,
            'financing' => $financingActivities,
            'period' => ['start' => $startDate, 'end' => $endDate],
        ];
    }

    private function updateAccountBalances(
        string $tenantId,
        string $sourceId,
        string $destId,
        string $amount
    ): void {
        $source = FinancialAccount::where('tenant_id', $tenantId)->findOrFail($sourceId);
        $dest = FinancialAccount::where('tenant_id', $tenantId)->findOrFail($destId);

        // createDoubleEntry writes the source line as credit, destination as debit.
        $this->applyEntryToBalance($source, 'credit', $amount);
        $this->applyEntryToBalance($dest, 'debit', $amount);
    }

    /**
     * Apply one ledger line to an account balance using normal-balance
     * semantics: debit-normal accounts (asset/expense/investment) grow on
     * debit; credit-normal accounts (liability/equity/revenue/income) grow
     * on credit.
     */
    public function applyEntryToBalance(FinancialAccount $account, string $side, string $amount): void
    {
        $debitNormal = in_array($account->type, self::DEBIT_NORMAL_TYPES, true);
        $increase = ($side === 'debit') === $debitNormal;

        $increase
            ? $account->increment('balance', $amount)
            : $account->decrement('balance', $amount);
    }

    /**
     * Post one balanced compound journal: N debit lines against a single
     * balancing credit line. Lines carry chart_account_id for per-category
     * GL reporting. Debit line total must equal the credit amount.
     *
     * @param array<int, array{account_id: string, amount: string, chart_account_id?: ?string, description?: ?string}> $debitLines
     */
    public function postCompoundEntry(
        string $tenantId,
        array $debitLines,
        string $creditAccountId,
        string $currency,
        string $description,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): Transaction {
        return DB::transaction(function () use (
            $tenantId, $debitLines, $creditAccountId, $currency,
            $description, $referenceType, $referenceId
        ) {
            $total = '0';
            foreach ($debitLines as $line) {
                $total = bcadd($total, (string) $line['amount'], 4);
            }

            if (bccomp($total, '0', 4) <= 0) {
                throw new \InvalidArgumentException('Compound entry requires a positive debit total.');
            }

            $transactionNumber = $this->generateTransactionNumber($tenantId);

            $transaction = Transaction::create([
                'tenant_id' => $tenantId,
                'transaction_number' => $transactionNumber,
                'type' => 'journal_entry',
                'amount' => $total,
                'currency' => $currency,
                'status' => 'completed',
                'description' => $description,
                'destination_account_id' => $creditAccountId,
                'initiated_at' => now(),
                'completed_at' => now(),
            ]);

            $creditAccount = FinancialAccount::where('tenant_id', $tenantId)->findOrFail($creditAccountId);

            foreach ($debitLines as $line) {
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'account_id' => $line['account_id'],
                    'chart_account_id' => $line['chart_account_id'] ?? null,
                    'transaction_id' => $transactionNumber,
                    'entry_type' => 'journal',
                    'side' => 'debit',
                    'amount' => $line['amount'],
                    'currency' => $currency,
                    'description' => $line['description'] ?? $description,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'posted_at' => now(),
                ]);

                $debitAccount = FinancialAccount::where('tenant_id', $tenantId)->findOrFail($line['account_id']);
                $this->applyEntryToBalance($debitAccount, 'debit', (string) $line['amount']);
            }

            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'account_id' => $creditAccountId,
                'transaction_id' => $transactionNumber,
                'entry_type' => 'journal',
                'side' => 'credit',
                'amount' => $total,
                'currency' => $currency,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'posted_at' => now(),
            ]);
            $this->applyEntryToBalance($creditAccount, 'credit', $total);

            $this->eventStore->append(
                $tenantId,
                'ledger',
                $transaction->id,
                'ledger.entry_posted',
                [
                    'transaction_id' => $transactionNumber,
                    'compound' => true,
                    'line_count' => count($debitLines) + 1,
                    'amount' => $total,
                    'currency' => $currency,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ]
            );

            return $transaction;
        });
    }

    /**
     * Recompute an account balance from its ledger lines using the corrected
     * normal-balance semantics. Used by ledger:rebuild-balances after the
     * sign-handling fix — historical balances were written with the old
     * (wrong for credit-normal accounts) logic.
     */
    public function recomputeBalance(FinancialAccount $account): string
    {
        $debitNormal = in_array($account->type, self::DEBIT_NORMAL_TYPES, true);

        $debits = (string) LedgerEntry::forTenant($account->tenant_id)
            ->where('account_id', $account->id)
            ->where('side', 'debit')
            ->sum('amount');
        $credits = (string) LedgerEntry::forTenant($account->tenant_id)
            ->where('account_id', $account->id)
            ->where('side', 'credit')
            ->sum('amount');

        return $debitNormal
            ? bcsub($debits, $credits, 4)
            : bcsub($credits, $debits, 4);
    }

    private function generateTransactionNumber(string $tenantId): string
    {
        return $this->documentNumbers->next($tenantId, 'transaction', 'TXN');
    }
}
