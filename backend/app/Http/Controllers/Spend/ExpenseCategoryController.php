<?php

namespace App\Http\Controllers\Spend;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $query = ExpenseCategory::forTenant($tenant->id);

        if ($request->boolean('active_only')) {
            $query->active();
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => [
                'sometimes', 'string', 'max:60',
                Rule::unique('expense_categories', 'slug')->where('tenant_id', $tenant->id),
            ],
            'gl_account_id' => 'sometimes|nullable|uuid',
            'per_expense_limit' => 'sometimes|nullable|numeric|min:0',
            'monthly_limit' => 'sometimes|nullable|numeric|min:0',
            'requires_receipt_over' => 'sometimes|nullable|numeric|min:0',
            'active' => 'sometimes|boolean',
            'metadata' => 'sometimes|nullable|array',
        ]);

        $slug = $validated['slug'] ?? Str::slug(Str::limit($validated['name'], 50, ''));

        if (! isset($validated['slug'])
            && ExpenseCategory::forTenant($tenant->id)->where('slug', $slug)->exists()) {
            return response()->json([
                'error' => 'A category with this slug already exists.',
            ], 422);
        }

        $category = ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'slug' => $slug,
            'gl_account_id' => $validated['gl_account_id'] ?? null,
            'per_expense_limit' => $validated['per_expense_limit'] ?? null,
            'monthly_limit' => $validated['monthly_limit'] ?? null,
            'requires_receipt_over' => $validated['requires_receipt_over'] ?? null,
            'active' => $validated['active'] ?? true,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return response()->json(['data' => $category], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $category = ExpenseCategory::forTenant($tenant->id)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => [
                'sometimes', 'string', 'max:60',
                Rule::unique('expense_categories', 'slug')
                    ->where('tenant_id', $tenant->id)
                    ->ignore($category->id),
            ],
            'gl_account_id' => 'sometimes|nullable|uuid',
            'per_expense_limit' => 'sometimes|nullable|numeric|min:0',
            'monthly_limit' => 'sometimes|nullable|numeric|min:0',
            'requires_receipt_over' => 'sometimes|nullable|numeric|min:0',
            'active' => 'sometimes|boolean',
            'metadata' => 'sometimes|nullable|array',
        ]);

        $category->update($validated);

        return response()->json(['data' => $category->fresh()]);
    }
}
