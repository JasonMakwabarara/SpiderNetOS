<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\FiscalDevice;
use App\Models\FiscalSubmission;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Enterprise\Fiscal\FdmsFiscalGateway;
use App\Services\Enterprise\Fiscal\FiscalGateway;
use App\Services\Enterprise\Fiscal\SandboxFiscalGateway;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;

class FiscalisationService
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly SandboxFiscalGateway $sandbox,
        private readonly FdmsFiscalGateway $fdms,
    ) {}

    public function registerDevice(string $tenantId, array $data): FiscalDevice
    {
        $status = $data['status'] ?? 'inactive';
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw new DomainException('Fiscal device status must be active or inactive.');
        }

        return FiscalDevice::create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'device_identifier' => $data['device_identifier'],
            'status' => $status,
            'credentials' => $data['credentials'] ?? null,
        ]);
    }

    public function fiscalise(string $tenantId, string $invoiceId, string $deviceId): FiscalSubmission
    {
        $device = FiscalDevice::forTenant($tenantId)->findOrFail($deviceId);
        $gateway = $this->gateway();

        if ($gateway instanceof FdmsFiscalGateway) {
            $this->assertLiveAuthorised($tenantId, $device);
        }

        $submission = DB::transaction(function () use ($tenantId, $invoiceId, $device) {
            $invoice = Invoice::forTenant($tenantId)->lockForUpdate()->findOrFail($invoiceId);

            if (in_array($invoice->fiscal_status, ['fiscalised', 'pending'], true)) {
                throw new DomainException('This invoice cannot be fiscalised again from its current state.');
            }

            $counter = (int) FiscalSubmission::forTenant($tenantId)
                ->where('fiscal_device_id', $device->id)
                ->count() + 1;

            $invoice->update(['fiscal_status' => 'pending']);

            $created = FiscalSubmission::create([
                'tenant_id' => $tenantId,
                'fiscal_device_id' => $device->id,
                'invoice_id' => $invoice->id,
                'fiscal_day' => now()->toDateString(),
                'receipt_counter' => $counter,
                'status' => 'pending',
                'driver' => config('fiscal.driver') === 'fdms' ? 'fdms' : 'sandbox',
                'is_live' => false,
            ]);

            $this->eventStore->append($tenantId, 'fiscal_submission', $created->id, 'fiscal.submission.started', [
                'submission_id' => $created->id,
                'invoice_id' => $invoice->id,
                'fiscal_device_id' => $device->id,
                'status' => 'pending',
            ]);

            return $created;
        });

        try {
            $result = $gateway->submit($submission, Invoice::forTenant($tenantId)->findOrFail($invoiceId));
        } catch (\Throwable $e) {
            DB::transaction(function () use ($tenantId, $submission) {
                $submission->update(['status' => 'failed']);
                Invoice::forTenant($tenantId)->where('id', $submission->invoice_id)->update([
                    'fiscal_status' => 'failed',
                ]);
                $this->eventStore->append($tenantId, 'fiscal_submission', $submission->id, 'fiscal.submission.failed', [
                    'submission_id' => $submission->id,
                    'invoice_id' => $submission->invoice_id,
                    'status' => 'failed',
                ]);
            });

            throw $e instanceof DomainException
                ? $e
                : new DomainException('Fiscal submission failed.');
        }

        return DB::transaction(function () use ($tenantId, $submission, $result) {
            $submission->update([
                'status' => 'accepted',
                'verification_code' => $result['verification_code'],
                'qr_payload' => $result['qr_payload'],
                'driver' => $result['driver'],
                'is_live' => $result['is_live'],
            ]);

            Invoice::forTenant($tenantId)->where('id', $submission->invoice_id)->update([
                'fiscal_status' => 'fiscalised',
                'fiscalised_at' => now(),
            ]);

            $this->eventStore->append($tenantId, 'fiscal_submission', $submission->id, 'fiscal.submission.succeeded', [
                'submission_id' => $submission->id,
                'invoice_id' => $submission->invoice_id,
                'status' => 'accepted',
                'is_live' => $result['is_live'],
                'driver' => $result['driver'],
            ]);

            return $submission->refresh();
        });
    }

    private function gateway(): FiscalGateway
    {
        return config('fiscal.driver') === 'fdms' ? $this->fdms : $this->sandbox;
    }

    private function assertLiveAuthorised(string $tenantId, FiscalDevice $device): void
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $enabled = (bool) ($settings['fiscalisation_enabled'] ?? false);
        $tin = trim((string) ($settings['tin'] ?? ''));
        $vatRegistered = (bool) ($settings['vat_registered'] ?? false);
        $vatNumber = trim((string) ($settings['vat_number'] ?? ''));
        $vatOk = ! $vatRegistered || $vatNumber !== '';

        if (
            config('fiscal.driver') !== 'fdms'
            || ! $enabled
            || $tin === ''
            || ! $vatOk
            || $device->status !== 'active'
            || ! $device->credentials_present
        ) {
            throw new DomainException('Live fiscalisation is not authorised for this tenant and device.');
        }
    }
}
