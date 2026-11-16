<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enterprise;

use App\Http\Controllers\Controller;
use App\Models\ClockEvent;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayRun;
use App\Models\Payslip;
use App\Models\StatutoryRule;
use App\Services\ApprovalEngine;
use App\Services\Enterprise\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function __construct(
        private readonly PayrollService $payroll,
        private readonly ApprovalEngine $approvals,
    ) {}

    public function rules(Request $request): JsonResponse
    {
        $rows = StatutoryRule::forTenant((string) $request->attributes->get('tenant_id'))
            ->orderByDesc('effective_from')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function storeRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3,4}$/'],
            'effective_from' => 'required|date',
            'paye_bands' => 'required|array|min:1',
            'paye_bands.*.up_to' => 'nullable|numeric|min:0',
            'paye_bands.*.rate' => 'required|numeric|min:0|max:1',
            'aids_levy_rate' => 'required|numeric|min:0|max:1',
            'nssa_employee_rate' => 'required|numeric|min:0|max:1',
            'nssa_employer_rate' => 'required|numeric|min:0|max:1',
            'nssa_ceiling' => 'required|numeric|min:0',
            'nec_rate' => 'required|numeric|min:0|max:1',
            'union_dues' => 'required|numeric|min:0',
        ]);
        $rule = $this->payroll->storeRule((string) $request->attributes->get('tenant_id'), $data);

        return response()->json(['data' => $rule], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = PayRun::forTenant((string) $request->attributes->get('tenant_id'))
            ->orderByDesc('period_end')
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date',
            'currency' => ['required', 'string', 'regex:/^[A-Za-z]{3,4}$/'],
        ]);
        $run = $this->payroll->openRun(
            (string) $request->attributes->get('tenant_id'),
            $data['period_start'],
            $data['period_end'],
            $data['currency'],
        );

        return response()->json(['data' => $this->payroll->present($run)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $run = PayRun::forTenant((string) $request->attributes->get('tenant_id'))->findOrFail($id);

        return response()->json(['data' => $this->payroll->present($run)]);
    }

    public function calculate(Request $request, string $id): JsonResponse
    {
        $run = $this->payroll->calculate((string) $request->attributes->get('tenant_id'), $id);

        return response()->json(['data' => $run]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $run = $this->payroll->submit(
            (string) $request->attributes->get('tenant_id'),
            (string) $request->user()->id,
            $id,
        );

        return response()->json(['data' => $run]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $run = PayRun::forTenant($tenantId)->findOrFail($id);
        $this->approvals->resolveApproval(
            (string) $run->approval_id,
            (string) $request->user()->id,
            true,
            (string) $request->input('reason', ''),
        );

        return response()->json(['data' => $this->payroll->present($run->fresh())]);
    }

    public function postRun(Request $request, string $id): JsonResponse
    {
        $run = $this->payroll->post((string) $request->attributes->get('tenant_id'), $id);

        return response()->json(['data' => $run]);
    }

    public function me(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $employee = Employee::forTenant($tenantId)->where('user_id', $request->user()->id)->first();
        if (! $employee) {
            return response()->json(['data' => null]);
        }
        $attendance = ClockEvent::forTenant($tenantId)
            ->where('employee_id', $employee->id)
            ->orderByDesc('recorded_at')
            ->limit(30)
            ->get(['id', 'type', 'recorded_at', 'source']);
        $leave = LeaveRequest::forTenant($tenantId)
            ->where('employee_id', $employee->id)
            ->with('leaveType')
            ->orderByDesc('starts_on')
            ->limit(30)
            ->get();
        $payslips = Payslip::forTenant($tenantId)
            ->where('employee_id', $employee->id)
            ->whereHas('payRun', fn ($query) => $query->whereIn('status', ['approved', 'posted']))
            ->with('payRun')
            ->orderByDesc('created_at')
            ->limit(24)
            ->get();

        return response()->json([
            'data' => [
                'employee' => $employee,
                'attendance' => $attendance,
                'leave' => $leave,
                'payslips' => $payslips,
            ],
        ]);
    }
}
