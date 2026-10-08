<?php

declare(strict_types=1);

namespace App\Services\Enterprise\Fiscal;

use App\Exceptions\DomainException;
use App\Models\FdmsDevice;
use App\Models\FdmsReceipt;
use App\Models\Invoice;
use App\Services\EventStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Live ZIMRA FDMS. One device belongs to one taxpayer tenant. Device state
 * (fiscal day, counters, global number, previous receipt hash) advances only
 * after FDMS accepts a request.
 */
class FdmsDeviceService
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly FdmsSigner $signer,
    ) {}

    public function syncConfig(string $tenantId): FdmsDevice
    {
        $client = $this->client($tenantId);
        $config = $client->getConfig();

        return DB::transaction(function () use ($tenantId, $config) {
            $device = $this->lockedDevice($tenantId);
            $device->update([
                'qr_url' => $config['qrUrl'] ?? null,
                'applicable_taxes' => $config['applicableTaxes'] ?? [],
                'vat_number' => $config['vatNumber'] ?? null,
            ]);

            return $device;
        });
    }

    /**
     * @return array{device: FdmsDevice, remote: array<string, mixed>}
     */
    public function status(string $tenantId): array
    {
        $remote = $this->client($tenantId)->getStatus();

        $device = DB::transaction(function () use ($tenantId, $remote) {
            $device = $this->lockedDevice($tenantId);
            $remoteStatus = (string) ($remote['fiscalDayStatus'] ?? '');
            if ($remoteStatus === 'FiscalDayClosed' && $device->fiscal_day_status !== 'FiscalDayClosed') {
                $device->update([
                    'fiscal_day_status' => 'FiscalDayClosed',
                    'receipt_counter' => 0,
                    'previous_receipt_hash' => null,
                    'counters' => [],
                ]);
                $this->record($tenantId, $device, 'enterprise.fiscal.day_closed');
            } elseif ($remoteStatus === 'FiscalDayCloseFailed' && $device->fiscal_day_status === 'FiscalDayCloseInitiated') {
                $device->update(['fiscal_day_status' => 'FiscalDayCloseFailed']);
            }

            return $device;
        });

        return ['device' => $device, 'remote' => $remote];
    }

    public function openDay(string $tenantId): FdmsDevice
    {
        $client = $this->client($tenantId);

        return DB::transaction(function () use ($tenantId, $client) {
            $device = $this->lockedDevice($tenantId);
            if ($device->fiscal_day_status !== 'FiscalDayClosed') {
                throw new DomainException('A fiscal day is already open or closing on this device.');
            }
            $remote = $client->getStatus();
            if (($remote['fiscalDayStatus'] ?? null) !== 'FiscalDayClosed') {
                throw new DomainException('FDMS reports the fiscal day as '.($remote['fiscalDayStatus'] ?? 'unknown').'.');
            }

            $dayNo = ((int) ($remote['lastFiscalDayNo'] ?? 0)) + 1;
            $opened = $this->now();
            $answer = $client->openDay($dayNo, $opened->format('Y-m-d\TH:i:s'));

            $device->update([
                'fiscal_day_status' => 'FiscalDayOpened',
                'fiscal_day_no' => (int) ($answer['fiscalDayNo'] ?? $dayNo),
                'fiscal_day_opened_at' => $opened->format('Y-m-d H:i:s'),
                'receipt_counter' => 0,
                'receipt_global_no' => max($device->receipt_global_no, (int) ($remote['lastReceiptGlobalNo'] ?? 0)),
                'previous_receipt_hash' => null,
                'last_receipt_date' => null,
                'counters' => [],
            ]);
            $this->record($tenantId, $device, 'enterprise.fiscal.day_opened');

            return $device;
        });
    }

    public function closeDay(string $tenantId): FdmsDevice
    {
        $client = $this->client($tenantId);
        $deviceId = $this->deviceId();

        return DB::transaction(function () use ($tenantId, $client, $deviceId) {
            $device = $this->lockedDevice($tenantId);
            if (! in_array($device->fiscal_day_status, ['FiscalDayOpened', 'FiscalDayCloseFailed'], true)) {
                throw new DomainException('No open fiscal day to close.');
            }
            if (FdmsReceipt::forTenant($tenantId)->where('fdms_device_id', $device->id)->where('status', 'pending')->exists()) {
                throw new DomainException('A receipt is awaiting FDMS confirmation. Resend it before closing the day.');
            }
            $red = FdmsReceipt::forTenant($tenantId)
                ->where('fdms_device_id', $device->id)
                ->where('fiscal_day_no', $device->fiscal_day_no)
                ->where('status', 'accepted')
                ->get()
                ->contains(fn (FdmsReceipt $r) => collect($r->validation_errors ?? [])
                    ->contains(fn ($e) => strcasecmp((string) ($e['validationErrorColor'] ?? ''), 'Red') === 0));
            if ($red) {
                throw new DomainException('This fiscal day has a Red receipt. Only ZIMRA can close it.');
            }

            $counters = $this->signer->sortCounters($this->counterList($device));
            $canonical = $this->signer->dayString(
                $deviceId,
                (int) $device->fiscal_day_no,
                $device->fiscal_day_opened_at->format('Y-m-d'),
                $counters,
            );
            $signature = $this->signer->sign($canonical, $this->privateKey(), config('fiscal.fdms.key_passphrase'));
            $client->closeDay((int) $device->fiscal_day_no, $counters, $signature, $device->receipt_counter);

            $device->update(['fiscal_day_status' => 'FiscalDayCloseInitiated']);
            $this->record($tenantId, $device, 'enterprise.fiscal.day_close_initiated');

            return $device;
        });
    }

    public function fiscaliseInvoice(string $tenantId, string $invoiceId, string $moneyType): FdmsReceipt
    {
        if (! in_array($moneyType, FdmsSigner::MONEY_TYPES, true)) {
            throw new DomainException('Unknown money type.');
        }
        $client = $this->client($tenantId);
        $deviceId = $this->deviceId();

        $failure = null;
        $receipt = DB::transaction(function () use ($tenantId, $invoiceId, $moneyType, $client, $deviceId, &$failure) {
            $device = $this->lockedDevice($tenantId);
            $invoice = Invoice::forTenant($tenantId)->with('lineItems')->lockForUpdate()->findOrFail($invoiceId);
            if ($invoice->vendor_id !== null || $invoice->purchase_order_id !== null) {
                throw new DomainException('Supplier invoices are fiscalised by the supplier, not on this device.');
            }
            if (! in_array($invoice->status, ['sent', 'paid'], true)) {
                throw new DomainException('Only a sent or paid sales invoice can be fiscalised.');
            }

            $existing = FdmsReceipt::forTenant($tenantId)->where('invoice_id', $invoice->id)->first();
            if ($existing?->status === 'accepted') {
                throw new DomainException('This invoice already has an FDMS receipt.');
            }
            if ($existing?->status === 'rejected') {
                $existing->delete();
                $existing = null;
            }
            $held = FdmsReceipt::forTenant($tenantId)
                ->where('fdms_device_id', $device->id)
                ->where('status', 'pending')
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
                ->exists();
            if ($held) {
                throw new DomainException('Another receipt is awaiting FDMS confirmation. Resend that invoice first.');
            }
            if (! in_array($device->fiscal_day_status, ['FiscalDayOpened', 'FiscalDayCloseFailed'], true)) {
                throw new DomainException('Open a fiscal day before fiscalising.');
            }
            if (! $device->qr_url || ! $device->applicable_taxes) {
                throw new DomainException('Fetch the FDMS device configuration before fiscalising.');
            }

            $receipt = $existing ?? $this->buildReceipt($tenantId, $device, $invoice, $moneyType, $deviceId);

            try {
                $answer = $client->submitReceipt($receipt->payload);
            } catch (FdmsException $e) {
                if (! $e->outcomeUnknown) {
                    $receipt->update(['status' => 'rejected', 'error_code' => $e->errorCode]);
                }
                $failure = $e;

                return $receipt;
            }

            $this->accept($tenantId, $device, $receipt, $answer, $deviceId);

            return $receipt;
        });

        if ($failure instanceof FdmsException) {
            throw $failure->outcomeUnknown
                ? new FdmsException('FDMS did not confirm the receipt. It is held with the same number; fiscalise this invoice again to resend it.', $failure->httpStatus, $failure->errorCode, true)
                : $failure;
        }

        return $receipt->refresh();
    }

    private function buildReceipt(string $tenantId, FdmsDevice $device, Invoice $invoice, string $moneyType, int $deviceId): FdmsReceipt
    {
        if ((float) $invoice->discount_amount > 0) {
            throw new DomainException('Invoices with a discount cannot be fiscalised yet.');
        }
        if ($invoice->lineItems->isEmpty()) {
            throw new DomainException('An invoice needs at least one line to be fiscalised.');
        }

        $lines = [];
        $groups = [];
        foreach ($invoice->lineItems->values() as $i => $item) {
            $tax = $this->taxFor($device, $item->metadata['fdms_tax_id'] ?? null, (float) $item->tax_rate);
            $price = round((float) $item->unit_price, 6);
            $quantity = round((float) $item->quantity, 6);
            $total = round($price * $quantity, 2);
            $line = [
                'receiptLineType' => 'Sale',
                'receiptLineNo' => $i + 1,
                'receiptLineName' => mb_substr((string) $item->description, 0, 200),
                'receiptLinePrice' => $price,
                'receiptLineQuantity' => $quantity,
                'receiptLineTotal' => $total,
                'taxID' => (int) $tax['taxID'],
            ];
            if (array_key_exists('taxPercent', $tax) && $tax['taxPercent'] !== null) {
                $line['taxPercent'] = (float) $tax['taxPercent'];
            }
            if (! empty($item->metadata['hs_code'])) {
                $line['receiptLineHSCode'] = (string) $item->metadata['hs_code'];
            }
            $lines[] = $line;

            $key = (int) $tax['taxID'];
            $groups[$key] ??= ['taxID' => $key, 'taxPercent' => $tax['taxPercent'] ?? null, 'cents' => 0];
            $groups[$key]['cents'] += FdmsSigner::cents($total);
        }

        ksort($groups);
        $taxes = [];
        $totalCents = 0;
        foreach ($groups as $group) {
            $taxCents = $group['taxPercent'] === null ? 0 : (int) round($group['cents'] * (float) $group['taxPercent'] / 100);
            $tax = ['taxID' => $group['taxID']];
            if ($group['taxPercent'] !== null) {
                $tax['taxPercent'] = (float) $group['taxPercent'];
            }
            $tax['taxAmount'] = $taxCents / 100;
            $tax['salesAmountWithTax'] = ($group['cents'] + $taxCents) / 100;
            $taxes[] = $tax;
            $totalCents += $group['cents'] + $taxCents;
        }

        if ($totalCents !== FdmsSigner::cents($invoice->total_amount)) {
            throw new DomainException('The invoice total does not equal the fiscal receipt total built from its lines.');
        }

        $date = $this->now();
        $floor = $device->last_receipt_date ?? $device->fiscal_day_opened_at;
        if ($floor !== null) {
            $floor = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $floor->format('Y-m-d H:i:s'), $this->timezone());
            if ($date <= $floor) {
                $date = $floor->addSecond();
            }
        }

        $counter = $device->receipt_counter + 1;
        $globalNo = $device->receipt_global_no + 1;
        $payload = [
            'receiptType' => 'FiscalInvoice',
            'receiptCurrency' => strtoupper(trim((string) $invoice->currency)),
            'receiptCounter' => $counter,
            'receiptGlobalNo' => $globalNo,
            'invoiceNo' => (string) $invoice->invoice_number,
            'receiptDate' => $date->format('Y-m-d\TH:i:s'),
            'receiptLinesTaxInclusive' => false,
            'receiptLines' => $lines,
            'receiptTaxes' => $taxes,
            'receiptPayments' => [['moneyTypeCode' => $moneyType, 'paymentAmount' => $totalCents / 100]],
            'receiptTotal' => $totalCents / 100,
            'receiptPrintForm' => 'InvoiceA4',
        ];

        $previous = $counter === 1 ? null : $device->previous_receipt_hash;
        $signature = $this->signer->sign(
            $this->signer->receiptString($deviceId, $payload, $previous),
            $this->privateKey(),
            config('fiscal.fdms.key_passphrase'),
        );
        $payload['receiptDeviceSignature'] = $signature;

        return FdmsReceipt::create([
            'tenant_id' => $tenantId,
            'fdms_device_id' => $device->id,
            'invoice_id' => $invoice->id,
            'receipt_type' => 'FiscalInvoice',
            'fiscal_day_no' => $device->fiscal_day_no,
            'receipt_counter' => $counter,
            'receipt_global_no' => $globalNo,
            'receipt_hash' => $signature['hash'],
            'receipt_signature' => $signature['signature'],
            'payload' => $payload,
            'status' => 'pending',
            'qr_data' => $this->signer->qrData((string) $device->qr_url, $deviceId, $date, $globalNo, $signature['signature']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function accept(string $tenantId, FdmsDevice $device, FdmsReceipt $receipt, array $answer, int $deviceId): void
    {
        $payload = $receipt->payload;
        $receipt->update([
            'status' => 'accepted',
            'fdms_receipt_id' => isset($answer['receiptID']) ? (int) $answer['receiptID'] : null,
            'operation_id' => $answer['operationID'] ?? null,
            'server_date' => isset($answer['serverDate']) ? str_replace('T', ' ', substr((string) $answer['serverDate'], 0, 19)) : null,
            'validation_errors' => $answer['validationErrors'] ?? [],
        ]);

        $counters = $device->counters ?? [];
        $currency = $payload['receiptCurrency'];
        foreach ($payload['receiptTaxes'] as $tax) {
            $percent = $tax['taxPercent'] ?? null;
            $this->bump($counters, 'SaleByTax', $currency, $tax['taxID'], $percent, null, FdmsSigner::cents($tax['salesAmountWithTax']));
            $this->bump($counters, 'SaleTaxByTax', $currency, $tax['taxID'], $percent, null, FdmsSigner::cents($tax['taxAmount']));
        }
        foreach ($payload['receiptPayments'] as $payment) {
            $this->bump($counters, 'BalanceByMoneyType', $currency, null, null, $payment['moneyTypeCode'], FdmsSigner::cents($payment['paymentAmount']));
        }

        $device->update([
            'receipt_counter' => $receipt->receipt_counter,
            'receipt_global_no' => $receipt->receipt_global_no,
            'previous_receipt_hash' => $receipt->receipt_hash,
            'last_receipt_date' => str_replace('T', ' ', $payload['receiptDate']),
            'counters' => $counters,
        ]);

        $this->eventStore->append($tenantId, 'fdms_receipt', $receipt->id, 'enterprise.fiscal.fdms_accepted', [
            'fdms_receipt_id' => $receipt->id,
            'invoice_id' => $receipt->invoice_id,
            'status' => 'accepted',
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $counters
     */
    private function bump(array &$counters, string $type, string $currency, ?int $taxId, int|float|null $percent, ?string $moneyType, int $cents): void
    {
        $key = implode('|', [$type, $currency, $taxId ?? '', FdmsSigner::percent($percent), $moneyType ?? '']);
        $counters[$key] ??= [
            'fiscalCounterType' => $type,
            'fiscalCounterCurrency' => $currency,
            'fiscalCounterTaxID' => $taxId,
            'fiscalCounterTaxPercent' => $percent,
            'fiscalCounterMoneyType' => $moneyType,
            'cents' => 0,
        ];
        $counters[$key]['cents'] += $cents;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function counterList(FdmsDevice $device): array
    {
        $out = [];
        foreach ($device->counters ?? [] as $c) {
            $row = [
                'fiscalCounterType' => $c['fiscalCounterType'],
                'fiscalCounterCurrency' => $c['fiscalCounterCurrency'],
            ];
            if ($c['fiscalCounterType'] === 'BalanceByMoneyType') {
                $row['fiscalCounterMoneyType'] = $c['fiscalCounterMoneyType'];
            } else {
                $row['fiscalCounterTaxID'] = $c['fiscalCounterTaxID'];
                if ($c['fiscalCounterTaxPercent'] !== null) {
                    $row['fiscalCounterTaxPercent'] = (float) $c['fiscalCounterTaxPercent'];
                }
            }
            $row['fiscalCounterValue'] = $c['cents'] / 100;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function taxFor(FdmsDevice $device, mixed $taxId, float $rate): array
    {
        foreach ($device->applicable_taxes ?? [] as $tax) {
            if ($taxId !== null && (int) $tax['taxID'] === (int) $taxId) {
                return $tax;
            }
        }
        if ($taxId === null) {
            foreach ($device->applicable_taxes ?? [] as $tax) {
                if (isset($tax['taxPercent']) && FdmsSigner::percent($tax['taxPercent']) === FdmsSigner::percent($rate)) {
                    return $tax;
                }
            }
        }

        throw new DomainException('No ZIMRA tax on this device matches '.FdmsSigner::percent($rate).'%.');
    }

    private function record(string $tenantId, FdmsDevice $device, string $event): void
    {
        $this->eventStore->append($tenantId, 'fdms_device', $device->id, $event, [
            'fdms_device_id' => $device->id,
            'fiscal_day_no' => $device->fiscal_day_no,
            'status' => $device->fiscal_day_status,
        ]);
    }

    private function lockedDevice(string $tenantId): FdmsDevice
    {
        $deviceId = $this->deviceId();
        FdmsDevice::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'device_id' => $deviceId],
            ['base_url' => (string) config('fiscal.fdms.base_url')],
        );

        return FdmsDevice::forTenant($tenantId)->where('device_id', $deviceId)->lockForUpdate()->firstOrFail();
    }

    private function client(string $tenantId): FdmsClient
    {
        $this->assertReady($tenantId);
        $c = config('fiscal.fdms');

        return new FdmsClient(
            (string) $c['base_url'],
            (int) $c['device_id'],
            (string) $c['model_name'],
            (string) $c['model_version'],
            (string) $c['cert_path'],
            (string) $c['key_path'],
            $c['key_passphrase'] ?: null,
            (int) ($c['timeout'] ?? 30),
        );
    }

    private function assertReady(string $tenantId): void
    {
        if (config('fiscal.live') !== true) {
            throw new DomainException('Live fiscalisation is off. Set FDMS_LIVE=true once the device is registered with ZIMRA.');
        }
        $c = (array) config('fiscal.fdms');
        if ((string) ($c['tenant_id'] ?? '') !== $tenantId) {
            throw new DomainException('This tenant does not own the configured FDMS device.');
        }
        $missing = [];
        foreach (['base_url', 'device_id', 'model_name', 'model_version'] as $field) {
            if (empty($c[$field])) {
                $missing[] = $field;
            }
        }
        foreach (['cert_path', 'key_path'] as $field) {
            if (empty($c[$field]) || ! is_readable((string) $c[$field])) {
                $missing[] = $field;
            }
        }
        if ($missing !== []) {
            throw new DomainException('FDMS is not configured: '.implode(', ', $missing).'.');
        }
    }

    private function deviceId(): int
    {
        return (int) config('fiscal.fdms.device_id');
    }

    private function privateKey(): string
    {
        return (string) file_get_contents((string) config('fiscal.fdms.key_path'));
    }

    private function timezone(): string
    {
        return (string) config('fiscal.fdms.timezone', 'Africa/Harare');
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfSecond();
    }
}
