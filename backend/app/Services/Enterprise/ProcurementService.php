<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Requisition;
use App\Models\Vendor;
use App\Services\EventStore;
use App\Services\Financial\DocumentNumberService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ProcurementService
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    /**
     * Writes the canonical spend vendor directory. There is no second vendor table.
     */
    public function createVendor(string $tenantId, array $data): Vendor
    {
        $vendor = Vendor::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'status' => 'active',
        ]);

        $this->eventStore->append($tenantId, 'vendor', $vendor->id, 'enterprise.vendor.created', [
            'vendor_id' => $vendor->id,
        ]);

        return $vendor;
    }

    public function createPurchaseOrder(string $tenantId, array $data): PurchaseOrder
    {
        $this->vendorForProcurement($tenantId, $data['vendor_id']);

        $requisition = null;
        if (! empty($data['requisition_id'])) {
            $requisition = Requisition::forTenant($tenantId)->with('lines')->findOrFail($data['requisition_id']);
            if ($requisition->status !== 'approved') {
                throw new DomainException('A purchase order can only cite a requisition approved for procurement.');
            }
        }

        $lines = $this->orderLines($data, $requisition);
        $amount = $this->orderAmount($lines, $data['amount'] ?? null);

        $order = DB::transaction(function () use ($tenantId, $data, $lines, $amount) {
            $order = PurchaseOrder::create([
                'tenant_id' => $tenantId,
                'vendor_id' => $data['vendor_id'],
                'requisition_id' => $data['requisition_id'] ?? null,
                'po_number' => $this->documentNumbers->nextSerial($tenantId, 'purchase_order', 'PO', 6),
                'status' => 'draft',
                'currency' => Money::code($data['currency']),
                'amount' => $amount,
                'description' => $data['description'] ?? null,
            ]);

            foreach ($lines as $line) {
                PurchaseOrderLine::create([
                    'purchase_order_id' => $order->id,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                ]);
            }

            return $order;
        });

        $this->eventStore->append($tenantId, 'purchase_order', $order->id, 'enterprise.purchase_order.created', [
            'purchase_order_id' => $order->id,
            'vendor_id' => $order->vendor_id,
            'requisition_id' => $order->requisition_id,
            'status' => 'draft',
        ]);

        return $order->load('lines');
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

            return $order->refresh()->load('lines');
        });
    }

    /**
     * Record quantities arrived against an issued purchase order.
     * Does not create or alter an invoice.
     *
     * @param  list<array{purchase_order_line_id: string, quantity: mixed}>  $lines
     */
    public function receive(string $tenantId, string $purchaseOrderId, array $lines): GoodsReceipt
    {
        if ($lines === []) {
            throw new DomainException('A goods receipt needs at least one line.');
        }

        return DB::transaction(function () use ($tenantId, $purchaseOrderId, $lines) {
            $order = PurchaseOrder::forTenant($tenantId)->lockForUpdate()->with('lines')->findOrFail($purchaseOrderId);

            if ($order->status !== 'issued') {
                throw new DomainException('Only an issued purchase order can be received.');
            }

            if ($order->lines->isEmpty()) {
                throw new DomainException('A purchase order with no lines cannot be received.');
            }

            $receipt = GoodsReceipt::create([
                'tenant_id' => $tenantId,
                'purchase_order_id' => $order->id,
                'received_at' => now(),
            ]);

            foreach ($lines as $line) {
                $orderLine = $order->lines->firstWhere('id', $line['purchase_order_line_id']);
                if ($orderLine === null) {
                    throw (new ModelNotFoundException)->setModel(PurchaseOrderLine::class, [$line['purchase_order_line_id']]);
                }

                $quantity = $this->quantity($line['quantity']);
                $already = $this->receivedQuantity($orderLine->id);
                $next = bcadd($already, $quantity, 4);
                if (bccomp($next, (string) $orderLine->quantity, 4) === 1) {
                    throw new DomainException('Received quantity cannot exceed the ordered quantity.');
                }

                GoodsReceiptLine::create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_line_id' => $orderLine->id,
                    'quantity' => $quantity,
                ]);
            }

            $fullyReceived = $order->lines->every(function (PurchaseOrderLine $orderLine): bool {
                return bccomp($this->receivedQuantity($orderLine->id), (string) $orderLine->quantity, 4) === 0;
            });
            if ($fullyReceived) {
                $order->update(['status' => 'received']);
            }

            $this->eventStore->append($tenantId, 'goods_receipt', $receipt->id, 'enterprise.goods_receipt.recorded', [
                'goods_receipt_id' => $receipt->id,
                'purchase_order_id' => $order->id,
                'status' => $order->status,
            ]);

            return $receipt->load('lines');
        });
    }

    private function vendorForProcurement(string $tenantId, string $vendorId): Vendor
    {
        $vendor = Vendor::forTenant($tenantId)->findOrFail($vendorId);
        if ($vendor->status !== 'active') {
            throw new DomainException('Only an active vendor can be used on a purchase order.');
        }

        return $vendor;
    }

    /**
     * @return list<array{description: string, quantity: string, unit_price: string}>
     */
    private function orderLines(array $data, ?Requisition $requisition): array
    {
        $source = $data['lines'] ?? null;
        if ($source === null && $requisition !== null) {
            $source = $requisition->lines->map(fn ($line): array => [
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
            ])->all();
        }

        if (! is_array($source) || $source === []) {
            return [];
        }

        return array_map(fn (array $line): array => [
            'description' => trim((string) $line['description']),
            'quantity' => $this->quantity($line['quantity']),
            'unit_price' => number_format((float) $line['unit_price'], 4, '.', ''),
        ], $source);
    }

    /**
     * @param  list<array{description: string, quantity: string, unit_price: string}>  $lines
     */
    private function orderAmount(array $lines, mixed $amount): string
    {
        if ($lines === []) {
            return Money::positive($amount);
        }

        $total = '0.0000';
        foreach ($lines as $line) {
            $total = bcadd($total, bcmul($line['quantity'], $line['unit_price'], 4), 4);
        }
        if (bccomp($total, '0', 4) !== 1) {
            throw new DomainException('Amount must be greater than zero.');
        }
        if ($amount !== null && bccomp(Money::positive($amount), $total, 4) !== 0) {
            throw new DomainException('Purchase order amount must equal the sum of its lines.');
        }

        return $total;
    }

    private function quantity(mixed $quantity): string
    {
        $formatted = number_format((float) $quantity, 4, '.', '');
        if (bccomp($formatted, '0', 4) !== 1) {
            throw new DomainException('Quantity must be greater than zero.');
        }

        return $formatted;
    }

    private function receivedQuantity(string $purchaseOrderLineId): string
    {
        $rows = GoodsReceiptLine::query()
            ->where('purchase_order_line_id', $purchaseOrderLineId)
            ->lockForUpdate()
            ->get();

        $total = '0.0000';
        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->quantity, 4);
        }

        return $total;
    }
}
