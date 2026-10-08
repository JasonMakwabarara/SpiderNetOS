<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enterprise;

use App\Http\Controllers\Controller;
use App\Models\AssetAssignment;
use App\Models\AttendanceDay;
use App\Models\ClockEvent;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\EmploymentContract;
use App\Models\HrAuditEntry;
use App\Models\LeaveRequest;
use App\Services\Enterprise\AttendanceService;
use App\Services\Enterprise\HrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PeopleController extends Controller
{
    public function __construct(
        private readonly HrService $hr,
        private readonly AttendanceService $attendance,
    ) {}

    public function departments(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');

        return response()->json([
            'data' => Department::forTenant($tenantId)->orderBy('name')->get(),
        ]);
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:255']);
        $department = $this->hr->createDepartment((string) $request->attributes->get('tenant_id'), $data['name']);

        return response()->json(['data' => $department], 201);
    }

    public function showDepartment(Request $request, string $id): JsonResponse
    {
        $department = Department::forTenant((string) $request->attributes->get('tenant_id'))->findOrFail($id);

        return response()->json(['data' => $department]);
    }

    public function numberingSettings(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->hr->numberingSettings((string) $request->attributes->get('tenant_id')),
        ]);
    }

    public function updateNumberingSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_number_prefix' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/'],
            'employee_number_padding' => 'required|integer|min:2|max:8',
            'next_employee_number' => 'prohibited',
        ]);

        return response()->json([
            'data' => $this->hr->updateNumberingSettings(
                (string) $request->attributes->get('tenant_id'),
                $data['employee_number_prefix'],
                (int) $data['employee_number_padding'],
            ),
        ]);
    }

    public function suggestJobDescription(Request $request): JsonResponse
    {
        $data = $request->validate([
            'position_title' => 'required|string|max:150',
        ]);

        return response()->json([
            'data' => ['job_description' => $this->hr->suggestJobDescription(
                (string) $request->attributes->get('tenant_id'),
                $data['position_title'],
            )],
        ]);
    }

    public function employees(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => 'sometimes|nullable|string|max:100',
            'department_id' => 'sometimes|nullable|uuid',
            'status' => 'sometimes|in:active,inactive,all',
            'page' => 'sometimes|integer|min:1',
        ]);
        $page = $this->hr->search((string) $request->attributes->get('tenant_id'), $filters);

        return response()->json($page);
    }

    public function storeEmployee(Request $request): JsonResponse
    {
        $data = $request->validate($this->ownedFieldRules() + [
            'first_name' => 'required|string|max:100',
            'surname' => 'required|string|max:100',
            'position_title' => 'required|string|max:150',
            'department_id' => 'nullable|uuid',
            'start_date' => 'nullable|date',
            'job_description' => 'nullable|string|max:5000',
        ]);
        $employee = $this->hr->createEmployee(
            (string) $request->attributes->get('tenant_id'),
            $data,
            (string) $request->user()->id,
        );

        return response()->json(['data' => $employee], 201);
    }

    public function showEmployee(Request $request, string $id): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $employee = Employee::forTenant($tenantId)->with(['department', 'position'])->findOrFail($id);
        $assets = AssetAssignment::forTenant($tenantId)
            ->where('employee_id', $employee->id)
            ->whereNull('returned_at')
            ->with('asset')
            ->orderByDesc('assigned_at')
            ->get();
        $attendance = ClockEvent::forTenant($tenantId)
            ->where('employee_id', $employee->id)
            ->orderByDesc('recorded_at')
            ->limit(30)
            ->get(['id', 'type', 'recorded_at']);
        $activity = HrAuditEntry::forTenant($tenantId)
            ->where('employee_id', $employee->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $employee,
            'attendance' => $attendance,
            'attendance_days' => AttendanceDay::forTenant($tenantId)->where('employee_id', $employee->id)->orderByDesc('work_date')->get(),
            'leave' => LeaveRequest::forTenant($tenantId)->where('employee_id', $employee->id)->orderByDesc('starts_on')->get(),
            'contracts' => EmploymentContract::forTenant($tenantId)->where('employee_id', $employee->id)->orderByDesc('created_at')->get(),
            'shift' => EmployeeShift::forTenant($tenantId)->where('employee_id', $employee->id)->with('shift')->orderByDesc('effective_from')->first(),
            'assets' => $assets,
            'activity' => $activity,
        ]);
    }

    public function updateEmployee(Request $request, string $id): JsonResponse
    {
        $data = $request->validate($this->ownedFieldRules() + [
            'first_name' => 'sometimes|required|string|max:100',
            'surname' => 'sometimes|required|string|max:100',
            'position_title' => 'sometimes|required|string|max:150',
            'department_id' => 'sometimes|nullable|uuid',
            'start_date' => 'sometimes|nullable|date',
            'job_description' => 'sometimes|nullable|string|max:5000',
        ]);
        $employee = $this->hr->updateEmployee(
            (string) $request->attributes->get('tenant_id'),
            $id,
            $data,
            (string) $request->user()->id,
        );

        return response()->json(['data' => $employee]);
    }

    public function deactivateEmployee(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'inactive_from' => 'nullable|date',
            'reason' => 'nullable|string|max:500',
            'status' => 'prohibited',
            'employee_number' => 'prohibited',
            'id' => 'prohibited',
            'tenant_id' => 'prohibited',
            'name' => 'prohibited',
        ]);
        $employee = $this->hr->deactivate(
            (string) $request->attributes->get('tenant_id'),
            $id,
            $data['inactive_from'] ?? null,
            $data['reason'] ?? null,
            (string) $request->user()->id,
        );

        return response()->json(['data' => $employee]);
    }

    public function exportAudit(Request $request, string $id): Response
    {
        $csv = $this->hr->exportAuditCsv(
            (string) $request->attributes->get('tenant_id'),
            $id,
            (string) $request->user()->id,
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="employee-audit.csv"',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function ownedFieldRules(): array
    {
        return [
            'id' => 'prohibited',
            'tenant_id' => 'prohibited',
            'employee_number' => 'prohibited',
            'name' => 'prohibited',
            'status' => 'prohibited',
        ];
    }

    public function clockEvents(Request $request): JsonResponse
    {
        $rows = ClockEvent::forTenant((string) $request->attributes->get('tenant_id'))
            ->orderByDesc('recorded_at')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function storeClockEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|uuid',
            'type' => 'required|in:in,out',
            'recorded_at' => 'nullable|date',
        ]);
        $event = $this->attendance->record(
            (string) $request->attributes->get('tenant_id'),
            $data['employee_id'],
            $data['type'],
            $data['recorded_at'] ?? null,
        );

        return response()->json(['data' => $event], 201);
    }

    public function showClockEvent(Request $request, string $id): JsonResponse
    {
        $event = ClockEvent::forTenant((string) $request->attributes->get('tenant_id'))->findOrFail($id);

        return response()->json(['data' => $event]);
    }
}
