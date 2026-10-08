<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\FiscalSubmission;
use App\Models\Invoice;
use App\Models\PayablesPosting;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;

class FiscalisationService
{
    public function __construct(
        private readonly EventStore $eventStore,
    ) {}

    /**
     * Sandbox only. A supplier fiscalises its own invoice with ZIMRA; live
     * receipts for the tenant's sales invoices go through FdmsDeviceService.
     */
    public function fiscalise(string $tenantId, string $invoiceId, string $environment = 'sandbox'): FiscalSubmission
    {
        if ($environment !== 'sandbox') {
            throw new DomainException('Supplier invoices are fiscalised by the supplier, not on this device.');
        }

        return DB::transaction(function () use ($tenantId, $invoiceId) {
            $invoice = Invoice::forTenant($tenantId)->lockForUpdate()->findOrFail($invoiceId);
            if (! PayablesPosting::forTenant($tenantId)->where('invoice_id', $invoice->id)->exists()) {
                throw new DomainException('Only a posted invoice can be fiscalised.');
            }
            if (FiscalSubmission::forTenant($tenantId)->where('invoice_id', $invoice->id)->exists()) {
                throw new DomainException('This invoice already has a fiscal submission.');
            }

            $submission = FiscalSubmission::create([
                'tenant_id' => $tenantId,
                'invoice_id' => $invoice->id,
                'environment' => 'sandbox',
                'status' => 'accepted',
                'fiscal_receipt_id' => 'SBX-'.substr(str_replace('-', '', $invoice->id), 0, 16),
            ]);

            $this->eventStore->append($tenantId, 'fiscal_submission', $submission->id, 'enterprise.fiscal.submitted', [
                'fiscal_submission_id' => $submission->id,
                'invoice_id' => $invoice->id,
                'environment' => 'sandbox',
                'status' => 'accepted',
            ]);

            return $submission;
        });
    }
}
