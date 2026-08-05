<?php

namespace App\Http\Controllers;

use App\Models\ApprovalPolicy;
use App\Models\ApprovalPolicyStep;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
| CRUD for multi-stage approval chain policies. Reads are open to any
| authenticated tenant member; writes are role:admin (route middleware).
*/
class ApprovalPolicyController extends Controller
{
    private const VALID_ROLES = ['viewer', 'member', 'admin', 'super_admin'];

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id')
            ?? $request->attributes->get('tenant')?->id;

        $policies = ApprovalPolicy::forTenant($tenantId)
            ->with('steps')
            ->orderBy('resource_type')
            ->orderByDesc('priority')
            ->get();

        return response()->json(['data' => $policies]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id')
            ?? $request->attributes->get('tenant')?->id;

        $validated = $this->validatePayload($request);

        $policy = DB::transaction(function () use ($tenantId, $validated) {
            $policy = ApprovalPolicy::create([
                'tenant_id' => $tenantId,
                'resource_type' => $validated['resource_type'],
                'action' => $validated['action'] ?? 'submit',
                'name' => $validated['name'],
                'enabled' => $validated['enabled'] ?? true,
                'priority' => $validated['priority'] ?? 0,
                'conditions' => $validated['conditions'] ?? null,
            ]);

            $this->syncSteps($policy, $validated['steps']);

            return $policy->load('steps');
        });

        return response()->json(['data' => $policy], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id')
            ?? $request->attributes->get('tenant')?->id;

        $policy = ApprovalPolicy::forTenant($tenantId)->findOrFail($id);
        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($policy, $validated) {
            $policy->update([
                'resource_type' => $validated['resource_type'],
                'action' => $validated['action'] ?? $policy->action,
                'name' => $validated['name'],
                'enabled' => $validated['enabled'] ?? $policy->enabled,
                'priority' => $validated['priority'] ?? $policy->priority,
                'conditions' => $validated['conditions'] ?? null,
            ]);

            $policy->steps()->delete();
            $this->syncSteps($policy, $validated['steps']);
        });

        return response()->json(['data' => $policy->fresh('steps')]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id')
            ?? $request->attributes->get('tenant')?->id;

        ApprovalPolicy::forTenant($tenantId)->findOrFail($id)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'resource_type' => 'required|string|max:32',
            'action' => 'nullable|string|max:32',
            'enabled' => 'boolean',
            'priority' => 'integer|min:0|max:1000',
            'conditions' => 'nullable|array',
            'conditions.min_amount' => 'nullable|numeric|min:0',
            'conditions.max_amount' => 'nullable|numeric|min:0',
            'conditions.currency' => 'nullable|string|max:10',
            'conditions.categories' => 'nullable|array',
            'steps' => 'required|array|min:1|max:10',
            'steps.*.approver_type' => 'required|in:role,user',
            'steps.*.approver_role' => 'nullable|in:' . implode(',', self::VALID_ROLES),
            'steps.*.approver_id' => 'nullable|uuid',
            'steps.*.expires_after_hours' => 'nullable|integer|min:1|max:720',
            'steps.*.escalate_to_role' => 'nullable|in:' . implode(',', self::VALID_ROLES),
        ]);
    }

    private function syncSteps(ApprovalPolicy $policy, array $steps): void
    {
        foreach (array_values($steps) as $index => $step) {
            if (($step['approver_type'] ?? 'role') === 'role' && empty($step['approver_role'])) {
                abort(422, 'Role-based steps require approver_role.');
            }
            if (($step['approver_type'] ?? null) === 'user') {
                $approver = User::where('id', $step['approver_id'] ?? '')
                    ->where('tenant_id', $policy->tenant_id)
                    ->first();
                if (!$approver) {
                    abort(422, 'User-based steps require an approver in this tenant.');
                }
            }

            ApprovalPolicyStep::create([
                'approval_policy_id' => $policy->id,
                'step_order' => $index + 1,
                'approver_type' => $step['approver_type'],
                'approver_role' => $step['approver_role'] ?? null,
                'approver_id' => $step['approver_id'] ?? null,
                'expires_after_hours' => $step['expires_after_hours'] ?? null,
                'escalate_to_role' => $step['escalate_to_role'] ?? null,
            ]);
        }
    }
}
