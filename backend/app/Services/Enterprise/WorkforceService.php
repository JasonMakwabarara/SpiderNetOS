<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\AttendanceDay;
use App\Models\ClockEvent;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\EmploymentContract;
use App\Models\FinancialAccount;
use App\Models\HrAuditEntry;
use App\Models\JobDescriptionTemplate;
use App\Models\LeaveRequest;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\Shift;
use App\Services\ApprovalEngine;
use App\Services\EventStore;
use App\Services\Financial\DocumentNumberService;
use App\Services\Financial\LedgerService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class WorkforceService
{
    public function __construct(
        private readonly EventStore $events,
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalEngine $approvals,
        private readonly LedgerService $ledger,
    ) {}

    public function createPosition(string $tenantId, string $name): Position
    {
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('A position needs a name.');
        }

        try {
            $position = Position::create(['tenant_id' => $tenantId, 'name' => $name]);
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('This position already exists.');
        }
        $this->events->append($tenantId, 'position', $position->id, 'enterprise.position.created', [
            'position_id' => $position->id,
            'status' => 'active',
        ]);

        return $position;
    }

    public function saveJobTemplate(string $tenantId, string $title, string $body): JobDescriptionTemplate
    {
        $key = mb_strtolower(trim($title));
        $body = trim($body);
        if ($key === '' || $body === '') {
            throw new DomainException('A job-description template needs a title and a body.');
        }

        $template = JobDescriptionTemplate::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'title_key' => $key],
            ['body' => $body],
        );
        $this->events->append($tenantId, 'job_description_template', $template->id, 'enterprise.job_template.saved', [
            'job_template_id' => $template->id,
            'status' => 'saved',
        ]);

        return $template;
    }

    /**
     * Links the position. Does not rewrite a job description the register already holds.
     */
    public function assignPosition(string $tenantId, string $employeeId, string $positionId, ?string $actorId): Employee
    {
        return DB::transaction(function () use ($tenantId, $employeeId, $positionId, $actorId) {
            $position = Position::forTenant($tenantId)->findOrFail($positionId);
            $employee = Employee::forTenant($tenantId)->lockForUpdate()->findOrFail($employeeId);
            $previous = $employee->position_title;
            $employee->position_id = $position->id;
            $employee->position_title = $position->name;
            $employee->save();

            if ($previous !== $position->name) {
                HrAuditEntry::create([
                    'tenant_id' => $tenantId,
                    'employee_id' => $employee->id,
                    'actor_user_id' => $actorId,
                    'event' => 'updated',
                    'field' => 'position_title',
                    'previous_value' => $previous,
                    'new_value' => $position->name,
                    'created_at' => now(),
                ]);
            }

            $this->events->append($tenantId, 'employee', $employee->id, 'enterprise.position.assigned', [
                'employee_id' => $employee->id,
                'position_id' => $position->id,
                'status' => 'assigned',
            ]);

            return $employee->refresh();
        });
    }

    public function createShift(string $tenantId, string $name, string $startsAt, string $endsAt, int $graceMinutes): Shift
    {
        if ($endsAt <= $startsAt) {
            throw new DomainException('A shift must end after it starts on the same day.');
        }
        if ($graceMinutes < 0 || $graceMinutes > 180) {
            throw new DomainException('Shift grace must be between 0 and 180 minutes.');
        }

        $shift = Shift::create([
            'tenant_id' => $tenantId,
            'name' => trim($name),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'grace_minutes' => $graceMinutes,
        ]);
        $this->events->append($tenantId, 'shift', $shift->id, 'enterprise.shift.created', [
            'shift_id' => $shift->id,
            'status' => 'active',
        ]);

        return $shift;
    }

    public function assignShift(string $tenantId, string $employeeId, string $shiftId, string $effectiveFrom): EmployeeShift
    {
        Employee::forTenant($tenantId)->findOrFail($employeeId);
        Shift::forTenant($tenantId)->findOrFail($shiftId);

        $row = EmployeeShift::create([
            'tenant_id' => $tenantId,
            'employee_id' => $employeeId,
            'shift_id' => $shiftId,
            'effective_from' => $effectiveFrom,
        ]);
        $this->events->append($tenantId, 'employee_shift', $row->id, 'enterprise.shift.assigned', [
            'employee_shift_id' => $row->id,
            'employee_id' => $employeeId,
            'shift_id' => $shiftId,
            'status' => 'assigned',
        ]);

        return $row->load('shift');
    }

    public function closeDay(string $tenantId, string $employeeId, string $workDate): AttendanceDay
    {
        return DB::transaction(function () use ($tenantId, $employeeId, $workDate) {
            Employee::forTenant($tenantId)->lockForUpdate()->findOrFail($employeeId);
            $existing = AttendanceDay::forTenant($tenantId)
                ->where('employee_id', $employeeId)
                ->whereDate('work_date', $workDate)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                throw new DomainException('This day is already closed.');
            }

            $start = Carbon::parse($workDate)->startOfDay();
            $clocks = ClockEvent::forTenant($tenantId)
                ->where('employee_id', $employeeId)
                ->where('recorded_at', '>=', $start)
                ->where('recorded_at', '<', $start->copy()->addDay())
                ->orderBy('recorded_at')
                ->lockForUpdate()
                ->get();

            $minutes = 0;
            $punctuality = 'absent';
            if ($clocks->isNotEmpty()) {
                $in = $clocks->firstWhere('type', 'in');
                $out = $in
                    ? $clocks->first(fn (ClockEvent $event) => $event->type === 'out' && $event->recorded_at->greaterThan($in->recorded_at))
                    : null;
                if (! $in || ! $out) {
                    throw new DomainException('A closed day with clock events needs a clock-in and a later clock-out.');
                }

                $assignment = EmployeeShift::forTenant($tenantId)
                    ->where('employee_id', $employeeId)
                    ->whereDate('effective_from', '<=', $workDate)
                    ->orderByDesc('effective_from')
                    ->with('shift')
                    ->first();
                if (! $assignment || ! $assignment->shift) {
                    throw new DomainException('Assign a shift before closing a day that has clock events.');
                }

                $seconds = $out->recorded_at->getTimestamp() - $in->recorded_at->getTimestamp();
                $minutes = intdiv($seconds, 60);
                $deadline = Carbon::parse($workDate.' '.$assignment->shift->starts_at)
                    ->addMinutes((int) $assignment->shift->grace_minutes);
                $punctuality = $in->recorded_at->greaterThan($deadline) ? 'late' : 'on_time';
            }

            $day = AttendanceDay::create([
                'tenant_id' => $tenantId,
                'employee_id' => $employeeId,
                'work_date' => $workDate,
                'minutes' => $minutes,
                'punctuality' => $punctuality,
            ]);
            $this->events->append($tenantId, 'attendance_day', $day->id, 'enterprise.attendance.day_closed', [
                'attendance_day_id' => $day->id,
                'employee_id' => $employeeId,
                'punctuality' => $punctuality,
            ]);

            return $day;
        });
    }

    public function createLeave(string $tenantId, string $employeeId, string $type, string $startsOn, string $endsOn): LeaveRequest
    {
        if (! in_array($type, ['annual', 'sick', 'unpaid'], true)) {
            throw new DomainException('Leave type must be annual, sick, or unpaid.');
        }
        if ($endsOn < $startsOn) {
            throw new DomainException('Leave must end on or after the day it starts.');
        }

        $employee = Employee::forTenant($tenantId)->findOrFail($employeeId);
        if ($employee->status !== 'active') {
            throw new DomainException('An inactive employee cannot request leave.');
        }

        $leave = LeaveRequest::create([
            'tenant_id' => $tenantId,
            'employee_id' => $employee->id,
            'leave_type' => $type,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => 'draft',
        ]);
        $this->events->append($tenantId, 'leave_request', $leave->id, 'enterprise.leave.created', [
            'leave_request_id' => $leave->id,
            'employee_id' => $employee->id,
            'status' => 'draft',
        ]);

        return $leave;
    }

    public function submitLeave(string $tenantId, string $requesterId, string $leaveId): LeaveRequest
    {
        return DB::transaction(function () use ($tenantId, $requesterId, $leaveId) {
            $leave = LeaveRequest::forTenant($tenantId)->lockForUpdate()->findOrFail($leaveId);
            if ($leave->status !== 'draft') {
                throw new DomainException('Only a draft leave request can be submitted.');
            }

            $leave->update(['status' => 'submitted']);
            $this->approvals->createApproval(
                $tenantId,
                $requesterId,
                'leave',
                'leave_request',
                $leave->id,
                'Leave submitted for approval',
                ['leave_request_id' => $leave->id],
            );
            $this->events->append($tenantId, 'leave_request', $leave->id, 'enterprise.leave.submitted', [
                'leave_request_id' => $leave->id,
                'employee_id' => $leave->employee_id,
                'status' => 'submitted',
            ]);

            return $leave->refresh();
        });
    }

    /**
     * Approval grants the leave. It does not change pay or employee status.
     */
    public function onApprovalResolved(string $tenantId, string $leaveId, bool $granted, string $response = ''): void
    {
        $leave = LeaveRequest::forTenant($tenantId)->lockForUpdate()->findOrFail($leaveId);
        if ($leave->status !== 'submitted') {
            throw new DomainException('Leave is not awaiting approval.');
        }

        if ($granted && $this->overlapsApproved($tenantId, $leave)) {
            throw new DomainException('This leave overlaps leave that is already approved.');
        }

        $status = $granted ? 'approved' : 'rejected';
        $leave->update(['status' => $status]);
        $this->events->append(
            $tenantId,
            'leave_request',
            $leave->id,
            $granted ? 'enterprise.leave.approved' : 'enterprise.leave.rejected',
            [
                'leave_request_id' => $leave->id,
                'employee_id' => $leave->employee_id,
                'status' => $status,
            ],
        );
    }

    public function createContract(string $tenantId, string $employeeId, array $data): EmploymentContract
    {
        return DB::transaction(function () use ($tenantId, $employeeId, $data) {
            $employee = Employee::forTenant($tenantId)->lockForUpdate()->findOrFail($employeeId);
            if ($employee->status !== 'active') {
                throw new DomainException('An inactive employee cannot receive a contract.');
            }
            if (! in_array($data['pay_period'], ['month', 'hour'], true)) {
                throw new DomainException('Pay period must be month or hour.');
            }

            $contract = EmploymentContract::create([
                'tenant_id' => $tenantId,
                'employee_id' => $employee->id,
                'position_id' => $employee->position_id,
                'contract_number' => $this->numbers->nextSerial($tenantId, 'contract', 'CTR', 6),
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null,
                'pay_amount' => Money::positive($data['pay_amount']),
                'currency' => Money::code($data['currency']),
                'pay_period' => $data['pay_period'],
                'status' => 'draft',
            ]);
            $this->events->append($tenantId, 'employment_contract', $contract->id, 'enterprise.contract.created', [
                'contract_id' => $contract->id,
                'employee_id' => $employee->id,
                'status' => 'draft',
            ]);

            return $contract;
        });
    }

    public function activateContract(string $tenantId, string $contractId): EmploymentContract
    {
        return DB::transaction(function () use ($tenantId, $contractId) {
            $contract = EmploymentContract::forTenant($tenantId)->lockForUpdate()->findOrFail($contractId);
            if ($contract->status !== 'draft') {
                throw new DomainException('Only a draft contract can be activated.');
            }
            $open = EmploymentContract::forTenant($tenantId)
                ->where('employee_id', $contract->employee_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->exists();
            if ($open) {
                throw new DomainException('This employee already has an active contract.');
            }

            $contract->update(['status' => 'active']);
            $this->events->append($tenantId, 'employment_contract', $contract->id, 'enterprise.contract.activated', [
                'contract_id' => $contract->id,
                'employee_id' => $contract->employee_id,
                'status' => 'active',
            ]);

            return $contract->refresh();
        });
    }

    public function endContract(string $tenantId, string $contractId): EmploymentContract
    {
        return DB::transaction(function () use ($tenantId, $contractId) {
            $contract = EmploymentContract::forTenant($tenantId)->lockForUpdate()->findOrFail($contractId);
            if ($contract->status !== 'active') {
                throw new DomainException('Only an active contract can be ended.');
            }
            $contract->update(['status' => 'ended']);
            $this->events->append($tenantId, 'employment_contract', $contract->id, 'enterprise.contract.ended', [
                'contract_id' => $contract->id,
                'employee_id' => $contract->employee_id,
                'status' => 'ended',
            ]);

            return $contract->refresh();
        });
    }

    public function draftPayroll(string $tenantId, string $periodStart, string $periodEnd, string $currency): PayrollRun
    {
        if ($periodEnd < $periodStart) {
            throw new DomainException('A payroll period must end on or after it starts.');
        }
        $currency = Money::code($currency);

        return DB::transaction(function () use ($tenantId, $periodStart, $periodEnd, $currency) {
            $contracts = EmploymentContract::forTenant($tenantId)
                ->where('status', 'active')
                ->where('currency', $currency)
                ->whereDate('starts_on', '<=', $periodEnd)
                ->where(function ($query) use ($periodStart) {
                    $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $periodStart);
                })
                ->lockForUpdate()
                ->get();

            $run = PayrollRun::create([
                'tenant_id' => $tenantId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'currency' => $currency,
                'status' => 'draft',
            ]);

            foreach ($contracts as $contract) {
                $employee = Employee::forTenant($tenantId)->find($contract->employee_id);
                if (! $employee || $employee->status !== 'active') {
                    continue;
                }
                $minutes = 0;
                if ($contract->pay_period === 'hour') {
                    $days = AttendanceDay::forTenant($tenantId)
                        ->where('employee_id', $employee->id)
                        ->whereDate('work_date', '>=', $periodStart)
                        ->whereDate('work_date', '<=', $periodEnd)
                        ->lockForUpdate()
                        ->get();
                    foreach ($days as $day) {
                        $minutes += (int) $day->minutes;
                    }
                    $amount = bcmul(bcdiv((string) $minutes, '60', 4), (string) $contract->pay_amount, 4);
                } else {
                    $amount = number_format((float) $contract->pay_amount, 4, '.', '');
                }
                if (bccomp($amount, '0', 4) !== 1) {
                    continue;
                }
                PayrollLine::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'contract_id' => $contract->id,
                    'minutes' => $minutes,
                    'amount' => $amount,
                ]);
            }

            if ($run->lines()->count() === 0) {
                throw new DomainException('No active contracts match this payroll period.');
            }

            $this->events->append($tenantId, 'payroll_run', $run->id, 'enterprise.payroll.drafted', [
                'payroll_run_id' => $run->id,
                'status' => 'draft',
            ]);

            return $run->load('lines');
        });
    }

    public function postPayroll(string $tenantId, string $payrollRunId): PayrollRun
    {
        return DB::transaction(function () use ($tenantId, $payrollRunId) {
            $run = PayrollRun::forTenant($tenantId)->lockForUpdate()->findOrFail($payrollRunId);
            if ($run->status !== 'draft') {
                throw new DomainException('Only a draft payroll run can be posted.');
            }
            $total = '0.0000';
            foreach ($run->lines()->lockForUpdate()->get() as $line) {
                $total = bcadd($total, (string) $line->amount, 4);
            }
            if (bccomp($total, '0', 4) !== 1) {
                throw new DomainException('A payroll run needs an amount to post.');
            }

            $payable = $this->account($tenantId, 'LIA-WAGES', 'Wages payable', 'liability', $run->currency);
            $expense = $this->account($tenantId, 'EXP-WAGES', 'Wages', 'expense', $run->currency);
            $this->ledger->createJournalEntry(
                $tenantId,
                $payable->id,
                $expense->id,
                $total,
                $run->currency,
                'Payroll',
                'payroll_run',
                $run->id,
            );
            $run->update(['status' => 'posted']);
            $this->events->append($tenantId, 'payroll_run', $run->id, 'enterprise.payroll.posted', [
                'payroll_run_id' => $run->id,
                'status' => 'posted',
            ]);

            return $run->load('lines');
        });
    }

    private function overlapsApproved(string $tenantId, LeaveRequest $leave): bool
    {
        return LeaveRequest::forTenant($tenantId)
            ->where('employee_id', $leave->employee_id)
            ->where('status', 'approved')
            ->where('id', '!=', $leave->id)
            ->whereDate('starts_on', '<=', $leave->ends_on)
            ->whereDate('ends_on', '>=', $leave->starts_on)
            ->lockForUpdate()
            ->exists();
    }

    private function account(string $tenantId, string $number, string $name, string $type, string $currency): FinancialAccount
    {
        return FinancialAccount::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'account_number' => $number],
            ['name' => $name, 'type' => $type, 'currency' => $currency, 'status' => 'active'],
        );
    }
}
