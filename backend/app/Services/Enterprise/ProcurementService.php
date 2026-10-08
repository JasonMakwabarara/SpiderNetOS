<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Models\Vendor;
use App\Services\DocumentNumberService;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;

class ProcurementService
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    public function createVendor(string $tenantId, array $data): Vendor
    {
        $vendor = Vendor::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
        ]);

        $this->eventStore->append($tenantId, 'vendor', $vendor->id, 'enterprise.vendor.created', [
            'vendor_id' => $vendor->id,
        ]);

        return $vendor;
    }

    public function createPurchaseOrder(string $tenantId, array $data): PurchaseOrder
    {
        Vendor::forTenant($tenantId)->findOrFail($data['vendor_id']);

        if (! empty($data['requisition_id'])) {
            Requisition::forTenant($tenantId)->findOrFail($data['requisition_id']);
        }

        $order = PurchaseOrder::create([
            'tenant_id' => $tenantId,
            'vendor_id' => $data['vendor_id'],
            'requisition_id' => $data['requisition_id'] ?? null,
            'po_number' => $this->documentNumbers->next($tenantId, 'purchase_order', 'PO-'),
            'status' => 'draft',
            'currency' => Money::code($data['currency']),
            'amount' => $data['amount'],
            'description' => $data['description'] ?? null,
        ]);

        $this->eventStore->append($tenantId, 'purchase_order', $order->id, 'enterprise.purchase_order.created', [
            'purchase_order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'requisition_id' => $order->requisition_id,
            'status' => 'draft',
        ]);

        return $order;
    }

    public function issue(string $tenantId, string $purchaseOrderId): PurchaseOrder
    {
        return DB::transaction(function () use ($tenantId, $purchaseOrderId) {
            $order = PurchaseOrder::forTenant($tenantId)->lockForUpdate()->findOrFail($purchaseOrderId);

            if ($order->status !== 'draft') {
                throw new DomainException('Only a draft purchase order can be issued.');
            }

            $order->update(['status' => 'issued']);

            $this->eventStore->append($tenantId, 'purchase_order', $order->id, 'enterprise.purchase_order.issued', [
                'purchase_order_id' => $order->id,
                'status' => 'issued',
            ]);

            return $order->refresh();
        });
    }
}
