<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enterprise;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDay;
use App\Models\EmploymentContract;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\Shift;
use App\Services\Enterprise\WorkforceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkforceController extends Controller
{
    public function __construct(private readonly WorkforceService $workforce) {}

    public function positions(Request $request): JsonResponse
    {
        $rows = Position::forTenant((string) $request->attributes->get('tenant_id'))->orderBy('name')->get();

        return response()->json(['data' => $rows]);
    }

    public function storePosition(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:150']);
        $position = $this->workforce->createPosition((string) $request->attributes->get('tenant_id'), $data['name']);

        return response()->json(['data' => $position], 201);
    }

    public function storeJobTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'position_title' => 'required|string|max:150',
            'body' => 'required|string|max:5000',
        ]);
        $template = $this->workforce->saveJobTemplate(
            (string) $request->attributes->get('tenant_id'),
            $data['position_title'],
            $data['body'],
        );

        return response()->json(['data' => $template], 201);
    }

    public function assignPosition(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['position_id' => 'required|uuid']);
        $employee = $this->workforce->assignPosition(
            (string) $request->attributes->get('tenant_id'),
            $id,
            $data['position_id'],
            (string) $request->user()->id,
        );

        return response()->json(['data' => $employee]);
    }

    public function shifts(Request $request): JsonResponse
    {
        $rows = Shift::forTenant((string) $request->attributes->get('tenant_id'))->orderBy('name')->get();

        return response()->json(['data' => $rows]);
    }

    public function storeShift(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'starts_at' => 'required|date_format:H:i',
            'ends_at' => 'required|date_format:H:i',
            'grace_minutes' => 'required|integer|min:0|max:180',
        ]);
        $shift = $this->workforce->createShift(
            (string) $request->attributes->get('tenant_id'),
            $data['name'],
            $data['starts_at'],
            $data['ends_at'],
            (int) $data['grace_minutes'],
        );

        return response()->json(['data' => $shift], 201);
    }

    public function assignShift(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'shift_id' => 'required|uuid',
            'effective_from' => 'required|date',
        ]);
        $row = $this->workforce->assignShift(
            (string) $request->attributes->get('tenant_id'),
            $id,
            $data['shift_id'],
            $data['effective_from'],
        );

        return response()->json(['data' => $row], 201);
    }

    public function attendanceDays(Request $request, string $id): JsonResponse
    {
        $rows = AttendanceDay::forTenant((string) $request->attributes->get('tenant_id'))
            ->where('employee_id', $id)
            ->orderByDesc('work_date')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function closeDay(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['work_date' => 'required|date']);
        $day = $this->workforce->closeDay(
            (string) $request->attributes->get('tenant_id'),
            $id,
            $data['work_date'],
        );

        return response()->json(['data' => $day], 201);
    }

    public function storeLeave(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'leave_type' => 'required|in:annual,sick,unpaid',
            'starts_on' => 'required|date',
            'ends_on' => 'required|date',
        ]);
        $leave = $this->workforce->createLeave(
            (string) $request->attributes->get('tenant_id'),
            $id,
            $data['leave_type'],
            $data['starts_on'],
            $data['ends_on'],
        );

        return response()->json(['data' => $leave], 201);
    }

    public function showLeave(Request $request, string $id): JsonResponse
    {
        $leave = LeaveRequest::forTenant((string) $request->attributes->get('tenant_id'))->findOrFail($id);

        return response()->json(['data' => $leave]);
    }

    public function submitLeave(Request $request, string $id): JsonResponse
    {
        $leave = $this->workforce->submitLeave(
            (string) $request->attributes->get('tenant_id'),
            (string) $request->user()->id,
            $id,
        );

        return response()->json(['data' => $leave]);
    }

    public function storeContract(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'starts_on' => 'required|date',
            'ends_on' => 'nullable|date',
            'pay_amount' => 'required|numeric|gt:0',
            'currency' => 'required|string|max:4',
            'pay_period' => 'required|in:month,hour',
        ]);
        $contract = $this->workforce->createContract((string) $request->attributes->get('tenant_id'), $id, $data);

        return response()->json(['data' => $contract], 201);
    }

    public function activateContract(Request $request, string $id): JsonResponse
    {
        $contract = $this->workforce->activateContract((string) $request->attributes->get('tenant_id'), $id);

        return response()->json(['data' => $contract]);
    }

    public function endContract(Request $request, string $id): JsonResponse
    {
        $contract = $this->workforce->endContract((string) $request->attributes->get('tenant_id'), $id);

        return response()->json(['data' => $contract]);
    }

    public function showContract(Request $request, string $id): JsonResponse
    {
        $contract = EmploymentContract::forTenant((string) $request->attributes->get('tenant_id'))->findOrFail($id);

        return response()->json(['data' => $contract]);
    }

    public function storePayroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date',
            'currency' => 'required|string|max:4',
        ]);
        $run = $this->workforce->draftPayroll(
            (string) $request->attributes->get('tenant_id'),
            $data['period_start'],
            $data['period_end'],
            $data['currency'],
        );

        return response()->json(['data' => $run], 201);
    }

    public function showPayroll(Request $request, string $id): JsonResponse
    {
        $run = PayrollRun::forTenant((string) $request->attributes->get('tenant_id'))->with('lines')->findOrFail($id);

        return response()->json(['data' => $run]);
    }

    public function postPayroll(Request $request, string $id): JsonResponse
    {
        $run = $this->workforce->postPayroll((string) $request->attributes->get('tenant_id'), $id);

        return response()->json(['data' => $run], 201);
    }
}
