<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enterprise;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\Vendor;
use App\Services\Enterprise\ProcurementService;
use App\Services\Enterprise\RequisitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchasingController extends Controller
{
    public function __construct(
        private readonly RequisitionService $requisitions,
        private readonly ProcurementService $procurement,
    ) {}

    public function indexRequisitions(Request $request): JsonResponse
    {
        $rows = Requisition::forTenant((string) $request->attributes->get('tenant_id'))
            ->with('lines')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function storeRequisition(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'employee_id' => 'nullable|uuid',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price' => 'required|numeric|gte:0',
        ]);
        $row = $this->requisitions->create(
            (string) $request->attributes->get('tenant_id'),
            (string) $request->user()->id,
            $data,
        );

        return response()->json(['data' => $row], 201);
    }

    public function showRequisition(Request $request, string $id): JsonResponse
    {
        $row = Requisition::forTenant((string) $request->attributes->get('tenant_id'))
            ->with('lines')
            ->findOrFail($id);

        return response()->json(['data' => $row]);
    }

    public function submitRequisition(Request $request, string $id): JsonResponse
    {
        $row = $this->requisitions->submit(
            (string) $request->attributes->get('tenant_id'),
            (string) $request->user()->id,
            $id,
        );

        return response()->json(['data' => $row]);
    }

    public function indexVendors(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Vendor::forTenant((string) $request->attributes->get('tenant_id'))->orderBy('name')->get(),
        ]);
    }

    public function storeVendor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
        ]);
        $vendor = $this->procurement->createVendor((string) $request->attributes->get('tenant_id'), $data);

        return response()->json(['data' => $vendor], 201);
    }

    public function showVendor(Request $request, string $id): JsonResponse
    {
        $vendor = Vendor::forTenant((string) $request->attributes->get('tenant_id'))->findOrFail($id);

        return response()->json(['data' => $vendor]);
    }

    public function indexPurchaseOrders(Request $request): JsonResponse
    {
        $rows = PurchaseOrder::forTenant((string) $request->attributes->get('tenant_id'))
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function storePurchaseOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => 'required|uuid',
            'requisition_id' => 'nullable|uuid',
            'currency' => 'required|string|max:4',
            'amount' => 'required|numeric|gte:0',
            'description' => 'nullable|string|max:2000',
        ]);
        $order = $this->procurement->createPurchaseOrder((string) $request->attributes->get('tenant_id'), $data);

        return response()->json(['data' => $order], 201);
    }

    public function showPurchaseOrder(Request $request, string $id): JsonResponse
    {
        $order = PurchaseOrder::forTenant((string) $request->attributes->get('tenant_id'))->findOrFail($id);

        return response()->json(['data' => $order]);
    }

    public function issuePurchaseOrder(Request $request, string $id): JsonResponse
    {
        $order = $this->procurement->issue((string) $request->attributes->get('tenant_id'), $id);

        return response()->json(['data' => $order]);
    }
}
