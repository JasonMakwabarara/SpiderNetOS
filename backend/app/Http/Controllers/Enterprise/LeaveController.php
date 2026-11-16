<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enterprise;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\ApprovalEngine;
use App\Services\Enterprise\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function __construct(
        private readonly LeaveService $leave,
        private readonly ApprovalEngine $approvals,
    ) {}

    public function types(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->leave->types((string) $request->attributes->get('tenant_id')),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = LeaveRequest::forTenant((string) $request->attributes->get('tenant_id'))
            ->with(['employee', 'leaveType'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|uuid',
            'leave_type_id' => 'required|uuid',
            'starts_on' => 'required|date',
            'ends_on' => 'required|date',
            'reason' => 'nullable|string|max:1000',
        ]);
        $tenantId = (string) $request->attributes->get('tenant_id');
        if (! $request->user()->atLeastRole('admin')) {
            $self = Employee::forTenant($tenantId)->where('user_id', $request->user()->id)->firstOrFail();
            $data['employee_id'] = $self->id;
        }
        $leave = $this->leave->request($tenantId, (string) $request->user()->id, $data);

        return response()->json(['data' => $leave], 201);
    }

    public function resolve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'approved' => 'required|boolean',
            'reason' => 'nullable|string|max:1000',
        ]);
        $tenantId = (string) $request->attributes->get('tenant_id');
        $leave = LeaveRequest::forTenant($tenantId)->findOrFail($id);
        $employee = Employee::forTenant($tenantId)->findOrFail($leave->employee_id);
        $manager = $employee->reports_to
            ? Employee::forTenant($tenantId)->find($employee->reports_to)
            : null;
        $allowed = $request->user()->atLeastRole('admin')
            || ($manager && $manager->user_id === $request->user()->id);
        if (! $allowed) {
            abort(403);
        }
        $this->approvals->resolveApproval(
            (string) $leave->approval_id,
            (string) $request->user()->id,
            (bool) $data['approved'],
            (string) ($data['reason'] ?? ''),
        );

        return response()->json([
            'data' => $leave->fresh()->load(['employee', 'leaveType']),
        ]);
    }
}
