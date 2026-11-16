<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\Employee;
use App\Models\FinancialAccount;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayRun;
use App\Models\Payslip;
use App\Models\StatutoryRule;
use App\Services\ApprovalEngine;
use App\Services\Financial\LedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    public function __construct(
        private readonly AttendanceHoursService $hours,
        private readonly StatutoryCalculator $statutory,
        private readonly ApprovalEngine $approvals,
        private readonly LedgerService $ledger,
    ) {}

    public function storeRule(string $tenantId, array $data): StatutoryRule
    {
        return StatutoryRule::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'currency' => Money::code($data['currency']),
            'effective_from' => $data['effective_from'],
            'definition' => [
                'paye_bands' => array_values($data['paye_bands']),
                'aids_levy_rate' => (float) $data['aids_levy_rate'],
                'nssa_employee_rate' => (float) $data['nssa_employee_rate'],
                'nssa_employer_rate' => (float) $data['nssa_employer_rate'],
                'nssa_ceiling' => (float) $data['nssa_ceiling'],
                'nec_rate' => (float) $data['nec_rate'],
                'union_dues' => (float) $data['union_dues'],
            ],
        ]);
    }

    public function openRun(string $tenantId, string $start, string $end, string $currency): PayRun
    {
        $currency = Money::code($currency);
        $periodStart = Carbon::parse($start)->startOfDay();
        $periodEnd = Carbon::parse($end)->startOfDay();
        if ($periodEnd->lt($periodStart)) {
            throw new DomainException('Pay period cannot end before it starts.');
        }

        return PayRun::create([
            'tenant_id' => $tenantId,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'currency' => $currency,
            'status' => 'draft',
        ]);
    }

    public function calculate(string $tenantId, string $payRunId): PayRun
    {
        return DB::transaction(function () use ($tenantId, $payRunId) {
            $run = PayRun::forTenant($tenantId)->lockForUpdate()->findOrFail($payRunId);
            if ($run->status === 'posted') {
                throw new DomainException('A posted pay run cannot be calculated again.');
            }
            if ($run->approval_id && $run->status !== 'approved') {
                $pending = DB::table('approvals')->where('id', $run->approval_id)->value('status');
                if ($pending === 'pending') {
                    throw new DomainException('Resolve the current approval before calculating again.');
                }
            }

            $rule = StatutoryRule::forTenant($tenantId)
                ->where('currency', $run->currency)
                ->whereDate('effective_from', '<=', $run->period_end->toDateString())
                ->orderByDesc('effective_from')
                ->first();
            if (! $rule) {
                throw new DomainException('Add a statutory rule for this currency before calculating pay.');
            }

            $run->payslips()->delete();
            $threshold = $this->hours->dailyMinutes($tenantId);
            $weekdays = $this->weekdays($run->period_start, $run->period_end);
            $employees = Employee::forTenant($tenantId)
                ->where('status', 'active')
                ->where('pay_currency', $run->currency)
                ->get();

            $grossTotal = 0;
            $netTotal = 0;
            $payeTotal = 0;
            foreach ($employees as $employee) {
                $summary = $this->hours->summarize(
                    $tenantId,
                    $employee->id,
                    $run->period_start->toDateString(),
                    $run->period_end->toDateString(),
                );
                $paidLeaveDays = $this->leaveDays($tenantId, $employee->id, $run, true);
                $unpaidLeaveDays = $this->leaveDays($tenantId, $employee->id, $run, false);
                $rate = Cents::from($employee->pay_rate);
                $payable = $summary['payable_minutes'];
                $overtime = $summary['overtime_minutes'];
                if ($employee->pay_basis === 'monthly') {
                    $gross = $rate;
                    if ($weekdays > 0 && $unpaidLeaveDays > 0) {
                        $gross -= (int) round($rate * min($unpaidLeaveDays, $weekdays) / $weekdays);
                    }
                    $gross = max(0, $gross);
                } elseif ($employee->pay_basis === 'daily') {
                    $worked = count($summary['days_worked']);
                    $paidExtra = max(0, $paidLeaveDays - $worked);
                    $days = $worked + $paidExtra;
                    $gross = $days * $rate;
                    if ($overtime > 0 && $threshold > 0) {
                        $gross += (int) round($overtime * $rate / $threshold);
                    }
                } else {
                    $regular = max(0, $payable - $overtime) + ($paidLeaveDays * $threshold);
                    $gross = (int) round($regular * $rate / 60) + (int) round($overtime * $rate * 1.5 / 60);
                }

                $deduction = $this->statutory->deduct($gross, $rule->definition ?? []);
                $lines = [
                    ['code' => 'gross', 'amount' => Cents::toString($gross)],
                    ['code' => 'paye', 'amount' => Cents::toString($deduction['paye'])],
                    ['code' => 'aids_levy', 'amount' => Cents::toString($deduction['aids'])],
                    ['code' => 'nssa_employee', 'amount' => Cents::toString($deduction['nssa_employee'])],
                    ['code' => 'nssa_employer', 'amount' => Cents::toString($deduction['nssa_employer'])],
                    ['code' => 'nec', 'amount' => Cents::toString($deduction['nec'])],
                    ['code' => 'union', 'amount' => Cents::toString($deduction['union'])],
                    ['code' => 'net', 'amount' => Cents::toString($deduction['net'])],
                ];
                Payslip::create([
                    'tenant_id' => $tenantId,
                    'pay_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'payable_minutes' => $payable,
                    'overtime_minutes' => $overtime,
                    'unpaid_leave_days' => $unpaidLeaveDays,
                    'gross' => Cents::toString($gross),
                    'net' => Cents::toString($deduction['net']),
                    'lines' => $lines,
                ]);
                $grossTotal += $gross;
                $netTotal += $deduction['net'];
                $payeTotal += $deduction['paye'];
            }

            $run->status = 'calculated';
            $run->approval_id = null;
            $run->statutory_rule_id = $rule->id;
            $run->summary = [
                'gross' => Cents::toString($grossTotal),
                'net' => Cents::toString($netTotal),
                'paye' => Cents::toString($payeTotal),
                'employees' => $employees->count(),
            ];
            $run->save();

            return $this->present($run);
        });
    }

    public function submit(string $tenantId, string $actorId, string $payRunId): PayRun
    {
        return DB::transaction(function () use ($tenantId, $actorId, $payRunId) {
            $run = PayRun::forTenant($tenantId)->lockForUpdate()->findOrFail($payRunId);
            if ($run->status !== 'calculated') {
                throw new DomainException('Only a calculated pay run can be submitted.');
            }
            $approval = $this->approvals->createApproval(
                $tenantId,
                $actorId,
                'payroll',
                'pay_run',
                $run->id,
                'Pay run '.$run->period_start->toDateString().' to '.$run->period_end->toDateString(),
                ['currency' => $run->currency],
            );
            $run->approval_id = $approval['id'];
            $run->save();

            return $this->present($run);
        });
    }

    public function onApprovalResolved(string $tenantId, string $payRunId, bool $granted, string $response = ''): void
    {
        $run = PayRun::forTenant($tenantId)->lockForUpdate()->find($payRunId);
        if (! $run || $run->status === 'posted') {
            return;
        }
        $run->status = $granted ? 'approved' : 'calculated';
        if (! $granted) {
            $run->approval_id = null;
        }
        $run->save();
    }

    public function post(string $tenantId, string $payRunId): PayRun
    {
        return DB::transaction(function () use ($tenantId, $payRunId) {
            $run = PayRun::forTenant($tenantId)->lockForUpdate()->findOrFail($payRunId);
            if ($run->status !== 'approved') {
                throw new DomainException('Only an approved pay run can be posted.');
            }
            $slips = $run->payslips()->get();
            $totals = [
                'gross' => 0,
                'net' => 0,
                'paye' => 0,
                'aids' => 0,
                'nssa_employee' => 0,
                'nssa_employer' => 0,
                'nec' => 0,
                'union' => 0,
            ];
            foreach ($slips as $slip) {
                $byCode = collect($slip->lines)->keyBy('code');
                $totals['gross'] += Cents::from($slip->gross);
                $totals['net'] += Cents::from($slip->net);
                foreach (['paye', 'aids_levy', 'nssa_employee', 'nssa_employer', 'nec', 'union'] as $code) {
                    $key = $code === 'aids_levy' ? 'aids' : $code;
                    $totals[$key] += Cents::from($byCode->get($code)['amount'] ?? 0);
                }
            }

            $wage = $totals['gross'] + $totals['nssa_employer'];
            if ($wage > 0) {
                $accounts = $this->accounts($tenantId, $run->currency);
                $lines = [
                    ['account_id' => $accounts['wages'], 'side' => 'debit', 'amount' => Cents::toString($wage)],
                    ['account_id' => $accounts['net'], 'side' => 'credit', 'amount' => Cents::toString($totals['net'])],
                    ['account_id' => $accounts['paye'], 'side' => 'credit', 'amount' => Cents::toString($totals['paye'])],
                    ['account_id' => $accounts['aids'], 'side' => 'credit', 'amount' => Cents::toString($totals['aids'])],
                    ['account_id' => $accounts['nssa'], 'side' => 'credit', 'amount' => Cents::toString($totals['nssa_employee'] + $totals['nssa_employer'])],
                    ['account_id' => $accounts['nec'], 'side' => 'credit', 'amount' => Cents::toString($totals['nec'])],
                    ['account_id' => $accounts['union'], 'side' => 'credit', 'amount' => Cents::toString($totals['union'])],
                ];
                $transaction = $this->ledger->postBalancedJournal(
                    $tenantId,
                    $lines,
                    $run->currency,
                    'Weft HR pay run '.$run->period_start->toDateString(),
                    'pay_run',
                    $run->id,
                );
                $run->journal_transaction_id = $transaction->id;
            }

            $run->status = 'posted';
            $run->save();

            return $this->present($run);
        });
    }

    public function present(PayRun $run): PayRun
    {
        $run->load(['payslips.employee']);
        $run->setAttribute('unsynced_sites', $this->hours->unsyncedSites(
            (string) $run->tenant_id,
            $run->period_end->toDateString(),
        ));

        return $run;
    }

    private function leaveDays(string $tenantId, string $employeeId, PayRun $run, bool $paid): int
    {
        $requests = LeaveRequest::forTenant($tenantId)
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->whereDate('ends_on', '>=', $run->period_start->toDateString())
            ->whereDate('starts_on', '<=', $run->period_end->toDateString())
            ->get();
        $days = 0;
        foreach ($requests as $request) {
            $type = LeaveType::forTenant($tenantId)->find($request->leave_type_id);
            if (! $type || (bool) $type->paid !== $paid) {
                continue;
            }
            $from = $request->starts_on->greaterThan($run->period_start) ? $request->starts_on->copy() : $run->period_start->copy();
            $to = $request->ends_on->lessThan($run->period_end) ? $request->ends_on->copy() : $run->period_end->copy();
            if ($to->lt($from)) {
                continue;
            }
            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                if ($cursor->isWeekday()) {
                    $days++;
                }
                $cursor->addDay();
            }
        }

        return $days;
    }

    private function weekdays(Carbon $start, Carbon $end): int
    {
        $days = 0;
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            if ($cursor->isWeekday()) {
                $days++;
            }
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * @return array<string, string>
     */
    private function accounts(string $tenantId, string $currency): array
    {
        $map = [
            'wages' => ['Wages expense', 'expense'],
            'net' => ['Net wages payable', 'liability'],
            'paye' => ['PAYE payable', 'liability'],
            'aids' => ['AIDS levy payable', 'liability'],
            'nssa' => ['NSSA payable', 'liability'],
            'nec' => ['NEC payable', 'liability'],
            'union' => ['Union dues payable', 'liability'],
        ];
        $ids = [];
        foreach ($map as $code => [$name, $type]) {
            $account = FinancialAccount::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'account_number' => 'WEFT-'.$code.'-'.$currency],
                [
                    'name' => $name.' '.$currency,
                    'type' => $type,
                    'currency' => $currency,
                    'status' => 'active',
                    'balance' => 0,
                    'opened_at' => now(),
                ],
            );
            $ids[$code] = $account->id;
        }

        return $ids;
    }
}
