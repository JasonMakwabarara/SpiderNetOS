<?php

namespace App\Http\Controllers\Spend;

use App\Http\Controllers\Controller;
use App\Models\RecurringBillTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecurringBillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $query = RecurringBillTemplate::forTenant($tenant->id)->with('vendor');

        if ($request->has('enabled')) {
            $query->where('enabled', filter_var($request->get('enabled'), FILTER_VALIDATE_BOOL));
        }

        $templates = $query->orderBy('next_run_date')->paginate($request->get('per_page', 20));

        return response()->json(['data' => $templates]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'vendor_id' => 'sometimes|nullable|uuid',
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'sometimes|string|max:10',
            'category_id' => 'sometimes|nullable|uuid',
            'cadence' => 'required|in:weekly,monthly',
            'day_of_month' => 'sometimes|nullable|integer|min:1|max:31',
            'next_run_date' => 'required|date',
            'autocreate' => 'sometimes|boolean',
            'enabled' => 'sometimes|boolean',
            'metadata' => 'sometimes|nullable|array',
        ]);

        $template = RecurringBillTemplate::create($validated + ['tenant_id' => $tenant->id]);

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $template = RecurringBillTemplate::forTenant($tenant->id)->findOrFail($id);

        $validated = $request->validate([
            'vendor_id' => 'sometimes|nullable|uuid',
            'name' => 'sometimes|string|max:255',
            'amount' => 'sometimes|numeric|min:0.01',
            'currency' => 'sometimes|string|max:10',
            'category_id' => 'sometimes|nullable|uuid',
            'cadence' => 'sometimes|in:weekly,monthly',
            'day_of_month' => 'sometimes|nullable|integer|min:1|max:31',
            'next_run_date' => 'sometimes|date',
            'autocreate' => 'sometimes|boolean',
            'enabled' => 'sometimes|boolean',
            'metadata' => 'sometimes|nullable|array',
        ]);

        $template->update($validated);

        return response()->json(['data' => $template->fresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $template = RecurringBillTemplate::forTenant($tenant->id)->findOrFail($id);

        $template->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
