<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\FinancialReport;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Wallet;

class ReportingService
{
    public function generateProfitAndLoss(string $tenantId, string $startDate, string $endDate): FinancialReport
    {
        $revenue = LedgerEntry::forTenant($tenantId)
            ->forDateRange($startDate, $endDate)
            ->debits()
            ->get()
            ->sum('amount');

        $expenses = LedgerEntry::forTenant($tenantId)
            ->forDateRange($startDate, $endDate)
            ->credits()
            ->get()
            ->sum('amount');

        $netProfit = (float) $revenue - (float) $expenses;

        $invoiceRevenue = Invoice::forTenant($tenantId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->sum('total_amount');

        $totalExpenses = Payment::forTenant($tenantId)
            ->where('status', 'completed')
            ->where('type', 'sent')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->sum('amount');

        $data = [
            'revenue' => [
                'invoice_revenue' => (float) $invoiceRevenue,
                'total' => (float) $invoiceRevenue,
            ],
            'expenses' => [
                'operating_expenses' => (float) $totalExpenses,
                'total' => (float) $totalExpenses,
            ],
            'net_profit' => (float) $invoiceRevenue - (float) $totalExpenses,
            'period' => ['start' => $startDate, 'end' => $endDate],
            'generated_at' => now()->toIso8601String(),
        ];

        return FinancialReport::create([
            'tenant_id' => $tenantId,
            'type' => 'profit_and_loss',
            'title' => "Profit & Loss Statement ({$startDate} to {$endDate})",
            'period_start' => $startDate,
            'period_end' => $endDate,
            'status' => 'completed',
            'data' => $data,
        ]);
    }

    public function generateBalanceSheet(string $tenantId, string $asOfDate): FinancialReport
    {
        $assets = LedgerEntry::forTenant($tenantId)
            ->where('posted_at', '<=', $asOfDate)
            ->get()
            ->filter(fn ($e) => in_array($e->account->type ?? '', ['asset']))
            ->reduce(fn ($carry, $e) => $carry + (($e->side === 'debit') ? (float) $e->amount : -(float) $e->amount), 0);

        $liabilities = LedgerEntry::forTenant($tenantId)
            ->where('posted_at', '<=', $asOfDate)
            ->get()
            ->filter(fn ($e) => in_array($e->account->type ?? '', ['liability']))
            ->reduce(fn ($carry, $e) => $carry + (($e->side === 'credit') ? (float) $e->amount : -(float) $e->amount), 0);

        $walletBalances = Wallet::forTenant($tenantId)
            ->where('status', 'active')
            ->get()
            ->sum('balance');

        $data = [
            'assets' => [
                'cash_and_equivalents' => (float) $walletBalances,
                'accounts_receivable' => (float) Invoice::forTenant($tenantId)
                    ->whereNotIn('status', ['paid', 'cancelled'])
                    ->sum('total_amount'),
                'total' => (float) $walletBalances + (float) $assets,
            ],
            'liabilities' => [
                'accounts_payable' => (float) $liabilities,
                'total' => (float) $liabilities,
            ],
            'equity' => [
                'retained_earnings' => (float) $assets - (float) $liabilities,
                'total' => (float) $assets - (float) $liabilities,
            ],
            'as_of_date' => $asOfDate,
            'generated_at' => now()->toIso8601String(),
        ];

        return FinancialReport::create([
            'tenant_id' => $tenantId,
            'type' => 'balance_sheet',
            'title' => "Balance Sheet (as of {$asOfDate})",
            'period_start' => $asOfDate,
            'period_end' => $asOfDate,
            'status' => 'completed',
            'data' => $data,
        ]);
    }

    public function generateCashFlowStatement(string $tenantId, string $startDate, string $endDate): FinancialReport
    {
        $cashInflows = Payment::forTenant($tenantId)
            ->where('status', 'completed')
            ->where('type', 'received')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->sum('amount');

        $cashOutflows = Payment::forTenant($tenantId)
            ->where('status', 'completed')
            ->where('type', 'sent')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->sum('amount');

        $netCashFlow = (float) $cashInflows - (float) $cashOutflows;

        $data = [
            'operating_activities' => [
                'cash_received' => (float) $cashInflows,
                'cash_paid' => (float) $cashOutflows,
                'net_cash_from_operations' => $netCashFlow,
            ],
            'investing_activities' => [
                'net_cash_from_investing' => 0,
            ],
            'financing_activities' => [
                'net_cash_from_financing' => 0,
            ],
            'net_change_in_cash' => $netCashFlow,
            'period' => ['start' => $startDate, 'end' => $endDate],
            'generated_at' => now()->toIso8601String(),
        ];

        return FinancialReport::create([
            'tenant_id' => $tenantId,
            'type' => 'cash_flow',
            'title' => "Cash Flow Statement ({$startDate} to {$endDate})",
            'period_start' => $startDate,
            'period_end' => $endDate,
            'status' => 'completed',
            'data' => $data,
        ]);
    }

    public function generateAgingReport(string $tenantId): array
    {
        $invoices = Invoice::forTenant($tenantId)
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->get();

        $aging = [
            'current' => ['count' => 0, 'amount' => 0],
            '1_30_days' => ['count' => 0, 'amount' => 0],
            '31_60_days' => ['count' => 0, 'amount' => 0],
            '61_90_days' => ['count' => 0, 'amount' => 0],
            '90_plus_days' => ['count' => 0, 'amount' => 0],
        ];

        foreach ($invoices as $invoice) {
            $daysOverdue = max(0, now()->diffInDays($invoice->due_date));

            if ($daysOverdue === 0) {
                $bucket = 'current';
            } elseif ($daysOverdue <= 30) {
                $bucket = '1_30_days';
            } elseif ($daysOverdue <= 60) {
                $bucket = '31_60_days';
            } elseif ($daysOverdue <= 90) {
                $bucket = '61_90_days';
            } else {
                $bucket = '90_plus_days';
            }

            $aging[$bucket]['count']++;
            $aging[$bucket]['amount'] += (float) $invoice->total_amount;
        }

        return $aging;
    }

    public function getDashboardMetrics(string $tenantId): array
    {
        $thisMonth = now()->copy()->startOfMonth()->toDateString();
        $lastMonth = now()->copy()->subMonth()->startOfMonth()->toDateString();

        $revenueThisMonth = Payment::forTenant($tenantId)
            ->where('status', 'completed')
            ->where('type', 'received')
            ->where('paid_at', '>=', $thisMonth)
            ->sum('amount');

        $revenueLastMonth = Payment::forTenant($tenantId)
            ->where('status', 'completed')
            ->where('type', 'received')
            ->whereBetween('paid_at', [$lastMonth, $thisMonth])
            ->sum('amount');

        $invoicesThisMonth = Invoice::forTenant($tenantId)
            ->where('created_at', '>=', $thisMonth)
            ->count();

        $outstandingInvoices = Invoice::forTenant($tenantId)
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->sum('total_amount');

        $pendingPayments = Payment::forTenant($tenantId)
            ->where('status', 'pending')
            ->sum('amount');

        return [
            'revenue_this_month' => (float) $revenueThisMonth,
            'revenue_last_month' => (float) $revenueLastMonth,
            'revenue_growth' => $revenueLastMonth > 0
                ? round(((float) $revenueThisMonth - (float) $revenueLastMonth) / (float) $revenueLastMonth * 100, 2)
                : 0,
            'invoices_this_month' => $invoicesThisMonth,
            'outstanding_invoices' => (float) $outstandingInvoices,
            'pending_payments' => (float) $pendingPayments,
            'total_wallet_balance' => (float) Wallet::forTenant($tenantId)
                ->where('status', 'active')
                ->sum('balance'),
        ];
    }
}
