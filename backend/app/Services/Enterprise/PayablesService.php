<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\FinancialAccount;
use App\Models\GoodsReceiptLine;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\PayablesPosting;
use App\Models\PurchaseOrder;
use App\Models\ThreeWayMatch;
use App\Models\ThreeWayMatchLine;
use App\Models\Vendor;
use App\Services\EventStore;
use App\Services\Financial\DocumentNumberService;
use App\Services\Financial\LedgerService;
use Illuminate\Support\Facades\DB;

class PayablesService
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly DocumentNumberService $documentNumbers,
        private readonly LedgerService $ledger,
    ) {}

    /**
     * A supplier invoice cites a received purchase order.
     * Numbering stays DocumentNumberService::next. This does not change invoice status rules.
     */
    public function invoiceFromPurchaseOrder(string $tenantId, string $purchaseOrderId): Invoice
    {
        return DB::transaction(function () use ($tenantId, $purchaseOrderId) {
            $order = PurchaseOrder::forTenant($tenantId)->lockForUpdate()->with('lines')->findOrFail($purchaseOrderId);
            if ($order->status !== 'received') {
                throw new DomainException('Only a received purchase order can be invoiced.');
            }
            if ($order->lines->isEmpty()) {
                throw new DomainException('A purchase order with no lines cannot be invoiced.');
            }
            if (Invoice::forTenant($tenantId)->where('purchase_order_id', $order->id)->exists()) {
                throw new DomainException('This purchase order already has a supplier invoice.');
            }

            $vendor = Vendor::forTenant($tenantId)->findOrFail($order->vendor_id);
            $subtotal = '0.0000';
            foreach ($order->lines as $line) {
                $subtotal = bcadd($subtotal, bcmul((string) $line->quantity, (string) $line->unit_price, 4), 4);
            }

            $invoice = Invoice::create([
                'tenant_id' => $tenantId,
                'invoice_number' => $this->documentNumbers->next($tenantId, 'invoice', 'INV'),
                'purchase_order_id' => $order->id,
                'vendor_id' => $vendor->id,
                'customer_name' => $vendor->name,
                'subtotal' => $subtotal,
                'tax_amount' => '0.0000',
                'discount_amount' => '0.0000',
                'total_amount' => $subtotal,
                'currency' => $order->currency,
                'status' => 'draft',
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
            ]);

            foreach ($order->lines as $line) {
                InvoiceLineItem::create([
                    'invoice_id' => $invoice->id,
                    'purchase_order_line_id' => $line->id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'tax_rate' => 0,
                    'total' => bcmul((string) $line->quantity, (string) $line->unit_price, 4),
                ]);
            }

            $this->eventStore->append($tenantId, 'invoice', $invoice->id, 'enterprise.invoice.linked', [
                'invoice_id' => $invoice->id,
                'purchase_order_id' => $order->id,
                'vendor_id' => $vendor->id,
                'status' => 'draft',
            ]);

            return $invoice->load('lineItems');
        });
    }

    public function match(string $tenantId, string $invoiceId): ThreeWayMatch
    {
        return DB::transaction(function () use ($tenantId, $invoiceId) {
            $invoice = Invoice::forTenant($tenantId)->lockForUpdate()->with('lineItems')->findOrFail($invoiceId);
            if ($invoice->purchase_order_id === null) {
                throw new DomainException('Only a purchase-order invoice can be matched.');
            }

            $order = PurchaseOrder::forTenant($tenantId)->with('lines')->findOrFail($invoice->purchase_order_id);
            ThreeWayMatch::forTenant($tenantId)->where('invoice_id', $invoice->id)->delete();

            $match = ThreeWayMatch::create([
                'tenant_id' => $tenantId,
                'purchase_order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'status' => 'matched',
            ]);

            $covered = [];
            foreach ($order->lines as $line) {
                $invoiced = '0.0000';
                $priceMatches = true;
                foreach ($invoice->lineItems as $item) {
                    if ($item->purchase_order_line_id === $line->id) {
                        $invoiced = bcadd($invoiced, (string) $item->quantity, 4);
                        if (bccomp((string) $item->unit_price, (string) $line->unit_price, 4) !== 0) {
                            $priceMatches = false;
                        }
                    }
                }
                $received = $this->receivedQuantity($line->id);
                $quantitiesMatch = bccomp((string) $line->quantity, $received, 4) === 0
                    && bccomp($received, $invoiced, 4) === 0;
                $lineStatus = $quantitiesMatch && $priceMatches ? 'matched' : 'exception';
                if ($lineStatus === 'exception') {
                    $match->status = 'exception';
                }
                ThreeWayMatchLine::create([
                    'three_way_match_id' => $match->id,
                    'purchase_order_line_id' => $line->id,
                    'ordered_quantity' => $line->quantity,
                    'received_quantity' => $received,
                    'invoiced_quantity' => $invoiced,
                    'status' => $lineStatus,
                ]);
                $covered[] = $line->id;
            }

            foreach ($invoice->lineItems as $item) {
                if ($item->purchase_order_line_id === null || ! in_array($item->purchase_order_line_id, $covered, true)) {
                    $match->status = 'exception';
                }
            }

            $match->save();

            $this->eventStore->append($tenantId, 'three_way_match', $match->id, 'enterprise.three_way_match.recorded', [
                'match_id' => $match->id,
                'invoice_id' => $invoice->id,
                'purchase_order_id' => $order->id,
                'status' => $match->status,
            ]);

            return $match->load('lines');
        });
    }

    public function post(string $tenantId, string $invoiceId): PayablesPosting
    {
        return DB::transaction(function () use ($tenantId, $invoiceId) {
            $invoice = Invoice::forTenant($tenantId)->lockForUpdate()->findOrFail($invoiceId);
            $match = ThreeWayMatch::forTenant($tenantId)->where('invoice_id', $invoice->id)->first();
            if ($match === null || $match->status !== 'matched') {
                throw new DomainException('Only a matched invoice can be posted.');
            }
            if (PayablesPosting::forTenant($tenantId)->where('invoice_id', $invoice->id)->exists()) {
                throw new DomainException('This invoice has already been posted.');
            }

            $expense = $this->account($tenantId, 'EXP-PURCHASES', 'Purchases', 'expense');
            $payable = $this->account($tenantId, 'LIA-AP', 'Accounts payable', 'liability');
            $transaction = $this->ledger->createJournalEntry(
                $tenantId,
                $payable->id,
                $expense->id,
                number_format((float) $invoice->total_amount, 4, '.', ''),
                $invoice->currency,
                'Supplier invoice',
                'invoice',
                $invoice->id,
            );

            $posting = PayablesPosting::create([
                'tenant_id' => $tenantId,
                'invoice_id' => $invoice->id,
                'transaction_id' => $transaction->id,
            ]);

            $this->eventStore->append($tenantId, 'payables_posting', $posting->id, 'enterprise.invoice.posted', [
                'posting_id' => $posting->id,
                'invoice_id' => $invoice->id,
                'purchase_order_id' => $invoice->purchase_order_id,
                'status' => 'posted',
            ]);

            return $posting;
        });
    }

    public function settle(string $tenantId, Invoice $invoice, string $amount): void
    {
        $invoice = Invoice::forTenant($tenantId)->lockForUpdate()->findOrFail($invoice->id);
        if ($invoice->status === 'paid') {
            throw new DomainException('This invoice is already paid.');
        }
        if (! PayablesPosting::forTenant($tenantId)->where('invoice_id', $invoice->id)->exists()) {
            throw new DomainException('Only a posted invoice can be settled from cash.');
        }
        if (bccomp($amount, number_format((float) $invoice->total_amount, 4, '.', ''), 4) !== 0) {
            throw new DomainException('Cash payment must equal the posted invoice total.');
        }

        $payable = $this->account($tenantId, 'LIA-AP', 'Accounts payable', 'liability');
        $cash = $this->account($tenantId, 'AST-CASH', 'Cash', 'asset');
        $this->ledger->createJournalEntry(
            $tenantId,
            $cash->id,
            $payable->id,
            $amount,
            $invoice->currency,
            'Supplier payment',
            'invoice',
            $invoice->id,
        );
        $invoice->update([
            'status' => 'paid',
            'paid_at' => now()->toDateString(),
        ]);
    }

    private function account(string $tenantId, string $number, string $name, string $type): FinancialAccount
    {
        return FinancialAccount::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'account_number' => $number],
            ['name' => $name, 'type' => $type, 'currency' => 'USD', 'status' => 'active'],
        );
    }

    private function receivedQuantity(string $purchaseOrderLineId): string
    {
        $total = '0.0000';
        $rows = GoodsReceiptLine::query()->where('purchase_order_line_id', $purchaseOrderLineId)->lockForUpdate()->get();
        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->quantity, 4);
        }

        return $total;
    }
}
