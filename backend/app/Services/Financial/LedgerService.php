<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Exceptions\DomainException;
use App\Models\LedgerEntry;
use App\Models\FinancialAccount;
use App\Models\ChartOfAccount;
use App\Models\Transaction;
use App\Services\Enterprise\Cents;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LedgerService
{
    public function __construct(
        private readonly EventStore $eventStore,
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

    /**
     * @param  list<array{account_id: string, side: string, amount: string, description?: string}>  $lines
     */
    public function postBalancedJournal(
        string $tenantId,
        array $lines,
        string $currency,
        string $description,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): Transaction {
        $grouped = [];
        foreach ($lines as $line) {
            $side = $line['side'] ?? '';
            if (! in_array($side, ['debit', 'credit'], true)) {
                throw new DomainException('Journal lines must be debit or credit.');
            }
            $cents = Cents::from($line['amount'] ?? 0);
            if ($cents < 0) {
                throw new DomainException('Journal amounts cannot be negative.');
            }
            if ($cents === 0) {
                continue;
            }
            $key = $line['account_id'].'|'.$side;
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'account_id' => $line['account_id'],
                    'side' => $side,
                    'cents' => 0,
                    'description' => $line['description'] ?? $description,
                ];
            }
            $grouped[$key]['cents'] += $cents;
        }

        $debit = 0;
        $credit = 0;
        foreach ($grouped as $line) {
            if ($line['side'] === 'debit') {
                $debit += $line['cents'];
            } else {
                $credit += $line['cents'];
            }
        }
        if ($debit === 0 || $debit !== $credit) {
            throw new DomainException('Journal debits and credits must balance.');
        }

        return DB::transaction(function () use ($tenantId, $grouped, $debit, $currency, $description, $referenceType, $referenceId) {
            $firstDebit = null;
            $firstCredit = null;
            foreach ($grouped as $line) {
                if ($line['side'] === 'debit' && ! $firstDebit) {
                    $firstDebit = $line['account_id'];
                }
                if ($line['side'] === 'credit' && ! $firstCredit) {
                    $firstCredit = $line['account_id'];
                }
            }

            $transaction = Transaction::create([
                'tenant_id' => $tenantId,
                'transaction_number' => $this->generateTransactionNumber($tenantId),
                'type' => 'journal_entry',
                'amount' => Cents::toString($debit),
                'currency' => $currency,
                'status' => 'completed',
                'description' => $description,
                'source_account_id' => $firstCredit,
                'destination_account_id' => $firstDebit,
                'initiated_at' => now(),
                'completed_at' => now(),
                'idempotency_key' => $referenceType && $referenceId ? $referenceType.':'.$referenceId : null,
            ]);

            foreach ($grouped as $line) {
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'account_id' => $line['account_id'],
                    'transaction_id' => $transaction->transaction_number,
                    'entry_type' => 'journal',
                    'side' => $line['side'],
                    'amount' => Cents::toString($line['cents']),
                    'currency' => $currency,
                    'description' => $line['description'],
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'posted_at' => now(),
                ]);
                $account = FinancialAccount::where('tenant_id', $tenantId)->findOrFail($line['account_id']);
                if ($line['side'] === 'debit') {
                    $account->increment('balance', Cents::toString($line['cents']));
                } else {
                    $account->decrement('balance', Cents::toString($line['cents']));
                }
            }

            $this->eventStore->append($tenantId, 'ledger', $transaction->id, 'ledger.entry_posted', [
                'transaction_id' => $transaction->transaction_number,
                'amount' => Cents::toString($debit),
                'currency' => $currency,
                'lines' => count($grouped),
            ]);

            return $transaction;
        });
    }

    private function updateAccountBalances(
        string $tenantId,
        string $sourceId,
        string $destId,
        string $amount
    ): void {
        $source = FinancialAccount::where('tenant_id', $tenantId)->findOrFail($sourceId);
        $dest = FinancialAccount::where('tenant_id', $tenantId)->findOrFail($destId);

        $source->decrement('balance', $amount);
        $dest->increment('balance', $amount);
    }

    private function generateTransactionNumber(string $tenantId): string
    {
        $count = Transaction::where('tenant_id', $tenantId)->count() + 1;
        return 'TXN-' . date('Ymd') . '-' . str_pad((string) $count, 6, '0', STR_PAD_LEFT);
    }
}
