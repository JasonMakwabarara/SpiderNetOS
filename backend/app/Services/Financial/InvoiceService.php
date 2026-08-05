<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Customer;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceService
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    public function createInvoice(
        string $tenantId,
        array $data,
        array $lineItems = [],
    ): Invoice {
        return DB::transaction(function () use ($tenantId, $data, $lineItems) {
            $subtotal = 0;
            foreach ($lineItems as $item) {
                $subtotal += (float) ($item['quantity'] ?? 1) * (float) $item['unit_price'];
            }

            $taxAmount = (float) ($data['tax_rate'] ?? 0) * $subtotal / 100;
            $discountAmount = (float) ($data['discount_amount'] ?? 0);
            $totalAmount = $subtotal + $taxAmount - $discountAmount;

            $invoice = Invoice::create([
                'tenant_id' => $tenantId,
                'invoice_number' => $this->generateInvoiceNumber($tenantId),
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'] ?? null,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'currency' => $data['currency'] ?? 'USD',
                'status' => $data['status'] ?? 'draft',
                'issue_date' => $data['issue_date'] ?? now()->toDateString(),
                'due_date' => $data['due_date'],
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
            ]);

            foreach ($lineItems as $item) {
                $lineTotal = (float) ($item['quantity'] ?? 1) * (float) $item['unit_price'];
                $taxRate = (float) ($item['tax_rate'] ?? 0);
                InvoiceLineItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'] ?? 1,
                    'unit' => $item['unit'] ?? 'each',
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $taxRate,
                    'total' => $lineTotal + ($lineTotal * $taxRate / 100),
                    'sku' => $item['sku'] ?? null,
                ]);
            }

            $this->eventStore->append(
                $tenantId,
                'invoice',
                $invoice->id,
                'invoice.created',
                [
                    'invoice_number' => $invoice->invoice_number,
                    'customer_name' => $invoice->customer_name,
                    'total_amount' => $totalAmount,
                    'due_date' => $invoice->due_date,
                ]
            );

            return $invoice->load('lineItems');
        });
    }

    public function sendInvoice(string $invoiceId, string $tenantId): Invoice
    {
        $invoice = Invoice::where('tenant_id', $tenantId)->findOrFail($invoiceId);

        if ($invoice->status !== 'draft') {
            throw new \RuntimeException("Invoice must be in draft status to send. Current status: {$invoice->status}");
        }

        $invoice->update([
            'status' => 'sent',
            'issue_date' => now()->toDateString(),
        ]);

        $this->eventStore->append(
            $tenantId,
            'invoice',
            $invoice->id,
            'invoice.sent',
            ['invoice_number' => $invoice->invoice_number]
        );

        return $invoice;
    }

    public function markInvoicePaid(string $invoiceId, string $tenantId, ?string $paymentId = null): Invoice
    {
        $invoice = Invoice::where('tenant_id', $tenantId)->findOrFail($invoiceId);

        $invoice->update([
            'status' => 'paid',
            'paid_at' => now()->toDateString(),
        ]);

        $this->eventStore->append(
            $tenantId,
            'invoice',
            $invoice->id,
            'invoice.paid',
            [
                'invoice_number' => $invoice->invoice_number,
                'amount' => $invoice->total_amount,
                'payment_id' => $paymentId,
            ]
        );

        return $invoice;
    }

    public function cancelInvoice(string $invoiceId, string $tenantId): Invoice
    {
        $invoice = Invoice::where('tenant_id', $tenantId)->findOrFail($invoiceId);

        if ($invoice->status === 'paid') {
            throw new \RuntimeException("Cannot cancel a paid invoice");
        }

        $invoice->update([
            'status' => 'cancelled',
            'cancelled_at' => now()->toDateString(),
        ]);

        $this->eventStore->append(
            $tenantId,
            'invoice',
            $invoice->id,
            'invoice.cancelled',
            ['invoice_number' => $invoice->invoice_number]
        );

        return $invoice;
    }

    public function getOverdueInvoices(string $tenantId): array
    {
        return Invoice::forTenant($tenantId)
            ->overdue()
            ->with('customer')
            ->orderBy('due_date')
            ->get()
            ->toArray();
    }

    public function getInvoiceSummary(string $tenantId): array
    {
        $summary = Invoice::forTenant($tenantId)
            ->selectRaw('status, COUNT(*) as count, SUM(total_amount) as total')
            ->groupBy('status')
            ->get();

        $result = [
            'draft' => ['count' => 0, 'total' => 0],
            'sent' => ['count' => 0, 'total' => 0],
            'paid' => ['count' => 0, 'total' => 0],
            'cancelled' => ['count' => 0, 'total' => 0],
            'overdue' => ['count' => 0, 'total' => 0],
        ];

        foreach ($summary as $row) {
            $result[$row->status] = ['count' => (int) $row->count, 'total' => (float) $row->total];
        }

        $result['overdue'] = ['count' => 0, 'total' => 0];
        $overdue = Invoice::forTenant($tenantId)->overdue()->get();
        foreach ($overdue as $inv) {
            $result['overdue']['count']++;
            $result['overdue']['total'] += (float) $inv->total_amount;
        }

        return $result;
    }

    private function generateInvoiceNumber(string $tenantId): string
    {
        return $this->documentNumbers->next($tenantId, 'invoice', 'INV');
    }
}
