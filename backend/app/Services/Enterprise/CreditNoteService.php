<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\CreditNote;
use App\Models\CreditNoteLineItem;
use App\Models\Invoice;
use App\Services\DocumentNumberService;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;

class CreditNoteService
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    public function create(string $tenantId, array $data): CreditNote
    {
        $lines = $data['lines'] ?? [];
        if ($lines === []) {
            throw new DomainException('A credit note needs at least one line.');
        }

        $invoice = Invoice::forTenant($tenantId)->findOrFail($data['invoice_id']);

        if (! empty($data['currency']) && Money::code($data['currency']) !== $invoice->currency) {
            throw new DomainException('Credit note currency must match the invoice currency.');
        }

        return DB::transaction(function () use ($tenantId, $data, $lines, $invoice) {
            $subtotal = 0.0;
            $tax = 0.0;
            $prepared = [];
            foreach ($lines as $line) {
                $qty = (float) $line['quantity'];
                $price = (float) $line['unit_price'];
                $rate = (float) ($line['tax_rate'] ?? 0);
                $lineNet = $qty * $price;
                $lineTax = $lineNet * $rate / 100;
                $subtotal += $lineNet;
                $tax += $lineTax;
                $prepared[] = [
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'tax_rate' => $rate,
                    'total' => round($lineNet + $lineTax, 4),
                ];
            }

            $note = CreditNote::create([
                'tenant_id' => $tenantId,
                'invoice_id' => $invoice->id,
                'credit_note_number' => $this->documentNumbers->next($tenantId, 'credit_note', 'CN-'),
                'reason' => $data['reason'] ?? null,
                'subtotal' => round($subtotal, 4),
                'tax_amount' => round($tax, 4),
                'total_amount' => round($subtotal + $tax, 4),
                'currency' => $invoice->currency,
                'status' => 'draft',
            ]);

            foreach ($prepared as $line) {
                CreditNoteLineItem::create(['credit_note_id' => $note->id] + $line);
            }

            $this->eventStore->append($tenantId, 'credit_note', $note->id, 'invoice.credit_note.created', [
                'credit_note_id' => $note->id,
                'invoice_id' => $invoice->id,
                'status' => 'draft',
                'currency' => $note->currency,
                'total_amount' => $note->total_amount,
            ]);

            return $note->load('lines');
        });
    }

    public function issue(string $tenantId, string $creditNoteId): CreditNote
    {
        return DB::transaction(function () use ($tenantId, $creditNoteId) {
            $note = CreditNote::forTenant($tenantId)->lockForUpdate()->findOrFail($creditNoteId);
            $invoice = Invoice::forTenant($tenantId)->lockForUpdate()->findOrFail($note->invoice_id);

            if ($note->status !== 'draft') {
                throw new DomainException('Only a draft credit note can be issued.');
            }

            if ($note->currency !== $invoice->currency) {
                throw new DomainException('Credit note currency must match the invoice currency.');
            }

            $issued = (float) CreditNote::forTenant($tenantId)
                ->where('invoice_id', $invoice->id)
                ->where('status', 'issued')
                ->sum('total_amount');

            if (round($issued + (float) $note->total_amount, 4) - (float) $invoice->total_amount > 0.0001) {
                throw new DomainException('Credit notes cannot exceed the invoice total.');
            }

            $note->update(['status' => 'issued']);

            $this->eventStore->append($tenantId, 'credit_note', $note->id, 'invoice.credit_note.issued', [
                'credit_note_id' => $note->id,
                'invoice_id' => $invoice->id,
                'status' => 'issued',
                'currency' => $note->currency,
                'total_amount' => $note->total_amount,
            ]);

            return $note->refresh()->load('lines');
        });
    }
}
