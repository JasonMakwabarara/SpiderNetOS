<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\PayablesPosting;
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
