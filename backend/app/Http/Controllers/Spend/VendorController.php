<?php

namespace App\Http\Controllers\Spend;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $query = Vendor::forTenant($tenant->id);

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $vendors = $query->orderBy('name')->paginate($request->get('per_page', 20));

        return response()->json(['data' => $vendors]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $vendor = Vendor::forTenant($tenant->id)->findOrFail($id);

        return response()->json(['data' => $vendor]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $this->validatePayload($request, creating: true);

        $vendor = Vendor::create($validated + ['tenant_id' => $tenant->id, 'status' => 'active']);

        return response()->json(['data' => $vendor], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $vendor = Vendor::forTenant($tenant->id)->findOrFail($id);

        $vendor->update($this->validatePayload($request, creating: false));

        return response()->json(['data' => $vendor->fresh()]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $vendor = Vendor::forTenant($tenant->id)->findOrFail($id);

        $vendor->update(['status' => 'archived']);

        return response()->json(['data' => $vendor->fresh()]);
    }

    private function validatePayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => ($creating ? 'required' : 'sometimes').'|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'sometimes|nullable|string|max:50',
            'company' => 'sometimes|nullable|string|max:255',
            'tax_id' => 'sometimes|nullable|string|max:100',
            'address' => 'sometimes|nullable|array',
            'currency' => 'sometimes|string|max:10',
            'payment_terms_days' => 'sometimes|nullable|integer|min:0|max:365',
            'default_gl_account_id' => 'sometimes|nullable|uuid',
            'bank_details' => 'sometimes|nullable|array',
            'metadata' => 'sometimes|nullable|array',
        ]);
    }
}
