<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\ApprovalEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveService
{
    public function __construct(private readonly ApprovalEngine $approvals) {}

    public function types(string $tenantId)
    {
        if (LeaveType::forTenant($tenantId)->doesntExist()) {
            foreach ([
                ['annual', 'Annual', true, 22],
                ['sick', 'Sick', true, 10],
                ['unpaid', 'Unpaid', false, 0],
            ] as [$code, $name, $paid, $days]) {
                LeaveType::create([
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'name' => $name,
                    'paid' => $paid,
                    'default_days' => $days,
                ]);
            }
        }

        return LeaveType::forTenant($tenantId)->orderBy('name')->get();
    }

    public function request(string $tenantId, string $actorId, array $data): LeaveRequest
    {
        return DB::transaction(function () use ($tenantId, $actorId, $data) {
            $employee = Employee::forTenant($tenantId)->lockForUpdate()->findOrFail($data['employee_id']);
            if ($employee->status !== 'active') {
                throw new DomainException('Leave can only be requested for an active employee.');
            }
            $type = LeaveType::forTenant($tenantId)->findOrFail($data['leave_type_id']);
            $start = Carbon::parse($data['starts_on'])->startOfDay();
            $end = Carbon::parse($data['ends_on'])->startOfDay();
            if ($end->lt($start)) {
                throw new DomainException('Leave cannot end before it starts.');
            }
            $days = 0;
            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                $days++;
                $cursor->addDay();
            }
            if ($type->paid) {
                $balance = $this->balance($tenantId, $employee, $type, (int) $start->year);
                $pending = LeaveRequest::forTenant($tenantId)
                    ->where('employee_id', $employee->id)
                    ->where('leave_type_id', $type->id)
                    ->where('status', 'pending')
                    ->whereYear('starts_on', $start->year)
                    ->sum('days');
                if ($balance->used + $pending + $days > $balance->entitled) {
                    throw new DomainException('Leave request exceeds the remaining balance.');
                }
            }

            $leave = LeaveRequest::create([
                'tenant_id' => $tenantId,
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'starts_on' => $start->toDateString(),
                'ends_on' => $end->toDateString(),
                'days' => $days,
                'status' => 'pending',
                'reason' => $data['reason'] ?? null,
            ]);

            $approval = $this->approvals->createApproval(
                $tenantId,
                $actorId,
                'leave',
                'leave_request',
                $leave->id,
                'Leave request',
                ['manager_employee_id' => $employee->reports_to],
            );
            $leave->approval_id = $approval['id'];
            $leave->save();

            return $leave->load(['employee', 'leaveType']);
        });
    }

    public function onApprovalResolved(string $tenantId, string $leaveId, bool $granted, string $response = ''): void
    {
        $leave = LeaveRequest::forTenant($tenantId)->lockForUpdate()->find($leaveId);
        if (! $leave || $leave->status !== 'pending') {
            return;
        }
        if (! $granted) {
            $leave->status = 'rejected';
            $leave->save();

            return;
        }

        $type = LeaveType::forTenant($tenantId)->findOrFail($leave->leave_type_id);
        $employee = Employee::forTenant($tenantId)->findOrFail($leave->employee_id);
        if ($type->paid) {
            $balance = $this->balance($tenantId, $employee, $type, (int) $leave->starts_on->year);
            $balance = LeaveBalance::query()->lockForUpdate()->findOrFail($balance->id);
            if ($balance->used + $leave->days > $balance->entitled) {
                throw new DomainException('Leave request exceeds the remaining balance.');
            }
            $balance->used += $leave->days;
            $balance->save();
        }
        $leave->status = 'approved';
        $leave->save();
    }

    private function balance(string $tenantId, Employee $employee, LeaveType $type, int $year): LeaveBalance
    {
        return LeaveBalance::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'year' => $year,
            ],
            ['entitled' => $type->default_days, 'used' => 0],
        );
    }
}
