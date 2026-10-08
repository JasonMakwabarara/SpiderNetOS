<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\PayablesPosting;
use App\Services\Enterprise\Fiscal\FdmsReceiptMath;
use App\Services\Enterprise\Fiscal\FdmsSigner;
use App\Services\EventStore;
use App\Services\Financial\DocumentNumberService;
use App\Services\Financial\LedgerService;
use Illuminate\Support\Facades\DB;

class CreditNoteService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly LedgerService $ledger,
        private readonly EventStore $events,
    ) {}

    /**
     * @param  list<array{description: string, quantity: string, unit_price: string}>  $lines
     */
    public function create(string $tenantId, string $invoiceId, array $lines, ?string $reason): CreditNote
    {
        if ($lines === []) {
            throw new DomainException('A credit note needs at least one line.');
        }

        return DB::transaction(function () use ($tenantId, $invoiceId, $lines, $reason): CreditNote {
            $invoice = Invoice::forTenant($tenantId)->lockForUpdate()->findOrFail($invoiceId);
            $currency = Money::code((string) $invoice->currency);
            $subtotal = '0.0000';

            foreach ($lines as $line) {
                $quantity = Money::positive((string) $line['quantity']);
                $price = Money::positive((string) $line['unit_price']);
                $subtotal = bcadd($subtotal, bcmul($quantity, $price, 4), 4);
            }

            $note = CreditNote::create([
                'tenant_id' => $tenantId,
                'invoice_id' => $invoice->id,
                'credit_note_number' => $this->numbers->next($tenantId, 'credit_note', 'CN'),
                'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                'subtotal' => $subtotal,
                'tax_amount' => '0.0000',
                'total_amount' => $subtotal,
                'currency' => $currency,
                'status' => 'draft',
            ]);

            foreach ($lines as $line) {
                $quantity = Money::positive((string) $line['quantity']);
                $price = Money::positive((string) $line['unit_price']);
                CreditNoteLine::create([
                    'credit_note_id' => $note->id,
                    'description' => trim((string) $line['description']),
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'line_total' => bcmul($quantity, $price, 4),
                ]);
            }

            $this->events->append(
                $tenantId,
                'credit_note',
                $note->id,
                'enterprise.credit_note.created',
                ['credit_note_id' => $note->id, 'invoice_id' => $invoice->id, 'status' => 'draft'],
            );

            return $note->load('lines');
        });
    }

    /**
     * A sales credit note credits quantities of the invoice's own lines, so it
     * carries their tax and a proportional share of any invoice discount.
     *
     * @param  list<array{invoice_line_item_id: string, quantity: string|int|float}>  $lines
     */
    public function createForSalesInvoice(string $tenantId, string $invoiceId, array $lines, string $reason): CreditNote
    {
        if ($lines === []) {
            throw new DomainException('A credit note needs at least one line.');
        }
        if (trim($reason) === '') {
            throw new DomainException('A sales credit note needs a reason.');
        }
        if (count(array_unique(array_column($lines, 'invoice_line_item_id'))) !== count($lines)) {
            throw new DomainException('Each invoice line can appear once on a credit note.');
        }

        return DB::transaction(function () use ($tenantId, $invoiceId, $lines, $reason): CreditNote {
            $invoice = Invoice::forTenant($tenantId)->with('lineItems')->lockForUpdate()->findOrFail($invoiceId);
            if ($invoice->vendor_id !== null || $invoice->purchase_order_id !== null) {
                throw new DomainException('Supplier invoices take a supplier credit note.');
            }
            if (! in_array($invoice->status, ['sent', 'paid'], true)) {
                throw new DomainException('Only a sent or paid sales invoice can be credited.');
            }

            $items = $invoice->lineItems->keyBy('id');
            $discounts = FdmsReceiptMath::invoiceDiscounts($invoice);
            $prior = CreditNoteLine::query()
                ->whereIn('credit_note_id', CreditNote::forTenant($tenantId)->where('invoice_id', $invoice->id)->select('id'))
                ->whereNotNull('invoice_line_item_id')
                ->get()
                ->groupBy('invoice_line_item_id');

            $rows = [];
            $math = [];
            $subtotal = '0.0000';
            foreach ($lines as $line) {
                $item = $items->get((string) $line['invoice_line_item_id']);
                if ($item === null) {
                    throw new DomainException('A credit note line does not belong to this invoice.');
                }
                $quantity = Money::positive($line['quantity']);
                $done = $prior->get($item->id) ?? collect();
                $remaining = bcsub((string) $item->quantity, number_format((float) $done->sum('quantity'), 4, '.', ''), 4);
                $left = bccomp($quantity, $remaining, 4);
                if ($left === 1) {
                    throw new DomainException('A credit note quantity exceeds what is left to credit on its invoice line.');
                }

                $share = $discounts[(string) $item->id] ?? 0;
                // The last credit on a line takes whatever discount is left, so full credits sum exactly.
                $discount = $left === 0
                    ? $share - FdmsSigner::cents((float) $done->sum('discount_amount'))
                    : (int) round($share * (float) $quantity / (float) $item->quantity);

                $lineTotal = bcmul($quantity, (string) $item->unit_price, 4);
                $subtotal = bcadd($subtotal, $lineTotal, 4);
                $rows[] = [
                    'description' => (string) $item->description,
                    'quantity' => $quantity,
                    'unit_price' => (string) $item->unit_price,
                    'line_total' => $lineTotal,
                    'invoice_line_item_id' => $item->id,
                    'tax_rate' => (string) $item->tax_rate,
                    'discount_amount' => number_format($discount / 100, 4, '.', ''),
                ];
                $math[] = [
                    'name' => (string) $item->description,
                    'price' => (float) $item->unit_price,
                    'quantity' => (float) $quantity,
                    'tax' => ['taxID' => (int) round((float) $item->tax_rate * 100), 'taxPercent' => (float) $item->tax_rate],
                    'hs_code' => null,
                    'discount_cents' => $discount,
                ];
            }

            $body = FdmsReceiptMath::body($math, $discounts !== [], 1);
            $taxCents = array_sum(array_map(fn (array $t) => FdmsSigner::cents($t['taxAmount']), $body['taxes']));

            $note = CreditNote::create([
                'tenant_id' => $tenantId,
                'invoice_id' => $invoice->id,
                'credit_note_number' => $this->numbers->next($tenantId, 'credit_note', 'CN'),
                'reason' => trim($reason),
                'subtotal' => $subtotal,
                'tax_amount' => number_format($taxCents / 100, 4, '.', ''),
                'total_amount' => number_format($body['total_cents'] / 100, 4, '.', ''),
                'currency' => Money::code((string) $invoice->currency),
                'status' => 'draft',
            ]);
            foreach ($rows as $row) {
                CreditNoteLine::create(['credit_note_id' => $note->id, ...$row]);
            }

            $this->events->append(
                $tenantId,
                'credit_note',
                $note->id,
                'enterprise.credit_note.created',
                ['credit_note_id' => $note->id, 'invoice_id' => $invoice->id, 'status' => 'draft'],
            );

            return $note->load('lines');
        });
    }

    public function issue(string $tenantId, string $creditNoteId): CreditNote
    {
        return DB::transaction(function () use ($tenantId, $creditNoteId): CreditNote {
            $note = CreditNote::forTenant($tenantId)->lockForUpdate()->findOrFail($creditNoteId);
            if ($note->status !== 'draft') {
                throw new DomainException('Only a draft credit note can be issued.');
            }

            $invoice = Invoice::forTenant($tenantId)->lockForUpdate()->findOrFail($note->invoice_id);
            $issued = '0.0000';
            foreach (CreditNote::forTenant($tenantId)->where('invoice_id', $invoice->id)->where('status', 'issued')->lockForUpdate()->get() as $existing) {
                $issued = bcadd($issued, (string) $existing->total_amount, 4);
            }

            $next = bcadd($issued, (string) $note->total_amount, 4);
            if (bccomp($next, (string) $invoice->total_amount, 4) === 1) {
                throw new DomainException('Credit notes cannot exceed the invoice total.');
            }

            if (PayablesPosting::forTenant($tenantId)->where('invoice_id', $invoice->id)->exists()) {
                $expense = FinancialAccount::query()->firstOrCreate(
                    ['tenant_id' => $tenantId, 'account_number' => 'EXP-PURCHASES'],
                    ['name' => 'Purchases', 'type' => 'expense', 'currency' => 'USD', 'status' => 'active'],
                );
                $payable = FinancialAccount::query()->firstOrCreate(
                    ['tenant_id' => $tenantId, 'account_number' => 'LIA-AP'],
                    ['name' => 'Accounts payable', 'type' => 'liability', 'currency' => 'USD', 'status' => 'active'],
                );
                $this->ledger->createJournalEntry(
                    $tenantId,
                    $expense->id,
                    $payable->id,
                    number_format((float) $note->total_amount, 4, '.', ''),
                    $note->currency,
                    'Supplier credit note',
                    'credit_note',
                    $note->id,
                );
            }

            $note->update(['status' => 'issued']);
            $this->events->append(
                $tenantId,
                'credit_note',
                $note->id,
                'enterprise.credit_note.issued',
                ['credit_note_id' => $note->id, 'invoice_id' => $invoice->id, 'status' => 'issued'],
            );

            return $note->fresh('lines');
        });
    }
}
