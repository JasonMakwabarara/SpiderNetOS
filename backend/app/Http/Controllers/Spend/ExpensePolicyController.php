<?php

namespace App\Http\Controllers\Spend;

use App\Http\Controllers\Controller;
use App\Models\ExpensePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpensePolicyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        return response()->json([
            'data' => ExpensePolicy::forTenant($tenant->id)
                ->with('category')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $this->validatePolicy($request, $tenant->id);

        $policy = ExpensePolicy::create($validated + ['tenant_id' => $tenant->id]);

        return response()->json(['data' => $policy->fresh('category')], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $policy = ExpensePolicy::forTenant($tenant->id)->findOrFail($id);

        $validated = $this->validatePolicy($request, $tenant->id, updating: true);

        $policy->update($validated);

        return response()->json(['data' => $policy->fresh('category')]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $policy = ExpensePolicy::forTenant($tenant->id)->findOrFail($id);

        $policy->delete();

        return response()->json(['data' => true]);
    }

    private function validatePolicy(Request $request, string $tenantId, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => "{$required}|string|max:255",
            'scope_type' => ['sometimes', Rule::in(['tenant', 'role', 'department'])],
            'scope_value' => 'sometimes|nullable|string|max:255',
            'category_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('expense_categories', 'id')->where('tenant_id', $tenantId),
            ],
            'per_expense_limit' => 'sometimes|nullable|numeric|min:0',
            'daily_limit' => 'sometimes|nullable|numeric|min:0',
            'monthly_limit' => 'sometimes|nullable|numeric|min:0',
            'receipt_required_over' => 'sometimes|nullable|numeric|min:0',
            'allowed_categories' => 'sometimes|nullable|array',
            'allowed_categories.*' => 'string|max:60',
            'enabled' => 'sometimes|boolean',
        ]);
    }
}
