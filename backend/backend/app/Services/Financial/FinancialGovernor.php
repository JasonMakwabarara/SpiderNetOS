<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\FinancialAlert;
use App\Models\Transaction;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\Budget;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;

class FinancialGovernor
{
    public function __construct(
        private readonly EventStore $eventStore,
    ) {}

    public function checkBudgetLimit(
        string $tenantId,
        string $budgetId,
        float $amount,
    ): array {
        $budget = Budget::where('tenant_id', $tenantId)->findOrFail($budgetId);

        $newSpent = (float) $budget->spent + $amount;
        $utilization = ($newSpent / (float) $budget->amount) * 100;

        if ($utilization >= 100) {
            $this->createAlert(
                $tenantId,
                'budget_exceeded',
                'critical',
                "Budget '{$budget->name}' exceeded",
                "Spent {$newSpent} of {$budget->amount} ({$utilization}%)"
            );
            return ['allowed' => false, 'reason' => 'Budget exceeded', 'utilization' => $utilization];
        }

        if ($utilization >= (float) $budget->alert_threshold) {
            $this->createAlert(
                $tenantId,
                'budget_warning',
                'warning',
                "Budget '{$budget->name}' nearing limit",
                "Spent {$newSpent} of {$budget->amount} ({$utilization}%)"
            );
        }

        return ['allowed' => true, 'utilization' => $utilization];
    }

    public function detectFraudulentTransaction(
        string $tenantId,
        string $transactionId,
        float $amount,
        string $method,
        ?string $counterpartyEmail = null,
    ): array {
        $riskScore = 0;
        $flags = [];

        $largeTransactionThreshold = 10000;
        if ($amount > $largeTransactionThreshold) {
            $riskScore += 20;
            $flags[] = 'large_amount';
        }

        if ($counterpartyEmail) {
            $previousTransactions = Transaction::where('tenant_id', $tenantId)
                ->where('counterparty_email', $counterpartyEmail)
                ->count();

            if ($previousTransactions === 0 && $amount > 1000) {
                $riskScore += 30;
                $flags[] = 'new_counterparty_large_amount';
            }
        }

        $recentTransactions = Transaction::where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->count();

        if ($recentTransactions > 5) {
            $riskScore += 25;
            $flags[] = 'high_frequency';
        }

        $unusualHours = (int) now()->format('H');
        if ($unusualHours >= 2 || $unusualHours <= 5) {
            $riskScore += 15;
            $flags[] = 'unusual_hour';
        }

        $status = 'approved';
        if ($riskScore >= 50) {
            $status = 'flagged';
            $this->createAlert(
                $tenantId,
                'fraud_suspected',
                'critical',
                "Suspicious transaction detected",
                "Transaction {$transactionId}: {$amount} via {$method}. Flags: " . implode(', ', $flags),
                ['transaction_id' => $transactionId, 'risk_score' => $riskScore, 'flags' => $flags]
            );
        }

        return [
            'risk_score' => $riskScore,
            'status' => $status,
            'flags' => $flags,
        ];
    }

    public function updateBudgetSpent(string $tenantId, string $budgetId, float $amount): void
    {
        Budget::where('tenant_id', $tenantId)
            ->where('id', $budgetId)
            ->increment('spent', $amount);
    }

    private function createAlert(
        string $tenantId,
        string $type,
        string $severity,
        string $title,
        string $message,
        ?array $context = null,
    ): FinancialAlert {
        return FinancialAlert::create([
            'tenant_id' => $tenantId,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'context' => $context,
            'status' => 'unread',
        ]);
    }
}
