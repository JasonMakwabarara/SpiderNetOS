<?php

declare(strict_types=1);

namespace App\Services\Enterprise\Fiscal;

use App\Exceptions\DomainException;
use App\Models\CreditNote;
use App\Models\FdmsDevice;
use App\Models\FdmsReceipt;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
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
        return $this->submit(
            $tenantId,
            $moneyType,
            'invoice',
            function () use ($tenantId, $invoiceId, $moneyType): array {
                $invoice = Invoice::forTenant($tenantId)->with('lineItems')->lockForUpdate()->findOrFail($invoiceId);
                if ($invoice->vendor_id !== null || $invoice->purchase_order_id !== null) {
                    throw new DomainException('Supplier invoices are fiscalised by the supplier, not on this device.');
                }
                if (! in_array($invoice->status, ['sent', 'paid'], true)) {
                    throw new DomainException('Only a sent or paid sales invoice can be fiscalised.');
                }

                return [
                    FdmsReceipt::forTenant($tenantId)->where('invoice_id', $invoice->id)->whereNull('credit_note_id')->first(),
                    fn (FdmsDevice $device, int $deviceId): FdmsReceipt => $this->buildInvoiceReceipt($tenantId, $device, $invoice, $moneyType, $deviceId),
                ];
            },
        );
    }

    public function fiscaliseCreditNote(string $tenantId, string $creditNoteId, string $moneyType): FdmsReceipt
    {
        return $this->submit(
            $tenantId,
            $moneyType,
            'credit note',
            function () use ($tenantId, $creditNoteId, $moneyType): array {
                $note = CreditNote::forTenant($tenantId)->with('lines.invoiceLineItem')->lockForUpdate()->findOrFail($creditNoteId);
                $invoice = Invoice::forTenant($tenantId)->lockForUpdate()->findOrFail($note->invoice_id);
                if ($invoice->vendor_id !== null || $invoice->purchase_order_id !== null) {
                    throw new DomainException('Supplier credit notes are fiscalised by the supplier, not on this device.');
                }
                if ($note->status !== 'issued') {
                    throw new DomainException('Issue the credit note before fiscalising it.');
                }
                if (trim((string) $note->reason) === '') {
                    throw new DomainException('ZIMRA requires a reason on every credit note.');
                }
                $original = FdmsReceipt::forTenant($tenantId)
                    ->with('device')
                    ->where('invoice_id', $invoice->id)
                    ->whereNull('credit_note_id')
                    ->where('status', 'accepted')
                    ->first();
                if ($original === null) {
                    throw new DomainException('Fiscalise the original invoice before its credit notes.');
                }

                return [
                    FdmsReceipt::forTenant($tenantId)->where('credit_note_id', $note->id)->first(),
                    fn (FdmsDevice $device, int $deviceId): FdmsReceipt => $this->buildCreditNoteReceipt($tenantId, $device, $note, $invoice, $original, $moneyType, $deviceId),
                ];
            },
        );
    }

    /**
     * Shared submission: a pending receipt is resent unchanged, a rejected one
     * is rebuilt, and only one receipt per device may await FDMS at a time.
     *
     * @param  \Closure(): array{0: ?FdmsReceipt, 1: \Closure(FdmsDevice, int): FdmsReceipt}  $find  Locks and checks the document; returns its existing receipt and a builder for a new one.
     */
    private function submit(string $tenantId, string $moneyType, string $label, \Closure $find): FdmsReceipt
    {
        if (! in_array($moneyType, FdmsSigner::MONEY_TYPES, true)) {
            throw new DomainException('Unknown money type.');
        }
        $client = $this->client($tenantId);
        $deviceId = $this->deviceId();

        $failure = null;
        $receipt = DB::transaction(function () use ($tenantId, $label, $find, $client, $deviceId, &$failure) {
            $device = $this->lockedDevice($tenantId);
            [$existing, $build] = $find();
            if ($existing?->status === 'accepted') {
                throw new DomainException('This '.$label.' already has an FDMS receipt.');
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
                throw new DomainException('Another receipt is awaiting FDMS confirmation. Resend that document first.');
            }
            if (! in_array($device->fiscal_day_status, ['FiscalDayOpened', 'FiscalDayCloseFailed'], true)) {
                throw new DomainException('Open a fiscal day before fiscalising.');
            }
            if (! $device->qr_url || ! $device->applicable_taxes) {
                throw new DomainException('Fetch the FDMS device configuration before fiscalising.');
            }

            $receipt = $existing ?? $build($device, $deviceId);

            try {
                $answer = $client->submitReceipt($receipt->payload);
            } catch (FdmsException $e) {
                if (! $e->outcomeUnknown) {
                    $receipt->update(['status' => 'rejected', 'error_code' => $e->errorCode]);
                }
                $failure = $e;

                return $receipt;
            }

            $this->accept($tenantId, $device, $receipt, $answer);

            return $receipt;
        });

        if ($failure instanceof FdmsException) {
            throw $failure->outcomeUnknown
                ? new FdmsException('FDMS did not confirm the receipt. It is held with the same number; fiscalise this '.$label.' again to resend it.', $failure->httpStatus, $failure->errorCode, true)
                : $failure;
        }

        return $receipt->refresh();
    }

    private function buildInvoiceReceipt(string $tenantId, FdmsDevice $device, Invoice $invoice, string $moneyType, int $deviceId): FdmsReceipt
    {
        if ($invoice->lineItems->isEmpty()) {
            throw new DomainException('An invoice needs at least one line to be fiscalised.');
        }

        $discounts = FdmsReceiptMath::invoiceDiscounts($invoice);
        $items = [];
        foreach ($invoice->lineItems as $item) {
            $items[] = $this->item($device, $item, (float) $item->quantity, $discounts[(string) $item->id] ?? 0);
        }
        // A discount is part of the billed total, so discounted receipts carry tax-inclusive lines.
        $inclusive = $discounts !== [];
        $body = FdmsReceiptMath::body($items, $inclusive, 1);

        if ($body['total_cents'] !== FdmsSigner::cents($invoice->total_amount)) {
            throw new DomainException('The invoice total does not equal the fiscal receipt total built from its lines.');
        }

        return $this->store($tenantId, $device, $deviceId, 'FiscalInvoice', $invoice->id, null, [
            'currency' => (string) $invoice->currency,
            'number' => (string) $invoice->invoice_number,
            'inclusive' => $inclusive,
            'extra' => [],
        ], $body, $moneyType);
    }

    private function buildCreditNoteReceipt(
        string $tenantId,
        FdmsDevice $device,
        CreditNote $note,
        Invoice $invoice,
        FdmsReceipt $original,
        string $moneyType,
        int $deviceId,
    ): FdmsReceipt {
        if ($note->lines->isEmpty()) {
            throw new DomainException('A credit note needs at least one line to be fiscalised.');
        }
        $issuedAt = $original->server_date ?? $original->created_at;
        if ($issuedAt !== null && $issuedAt->lt($this->now()->subYear())) {
            throw new DomainException('ZIMRA does not accept credit notes for receipts issued more than 12 months ago.');
        }

        $items = [];
        foreach ($note->lines as $line) {
            $source = $line->invoiceLineItem;
            if ($source === null || $source->invoice_id !== $invoice->id) {
                throw new DomainException('Every credit note line must point at a line of the original invoice.');
            }
            $items[] = $this->item($device, $source, (float) $line->quantity, FdmsSigner::cents($line->discount_amount));
        }
        $inclusive = (bool) ($original->payload['receiptLinesTaxInclusive'] ?? false);
        $body = FdmsReceiptMath::body($items, $inclusive, -1);

        $credit = -$body['total_cents'];
        if ($credit !== FdmsSigner::cents($note->total_amount)) {
            throw new DomainException('The credit note total does not equal the fiscal receipt total built from its lines.');
        }
        $credited = FdmsReceipt::forTenant($tenantId)
            ->where('invoice_id', $invoice->id)
            ->whereNotNull('credit_note_id')
            ->where('status', 'accepted')
            ->get()
            ->sum(fn (FdmsReceipt $r) => -FdmsSigner::cents($r->payload['receiptTotal']));
        if ($credited + $credit > FdmsSigner::cents($original->payload['receiptTotal'])) {
            throw new DomainException('Credit notes cannot exceed the fiscalised invoice total.');
        }

        return $this->store($tenantId, $device, $deviceId, 'CreditNote', $invoice->id, $note->id, [
            'currency' => (string) $invoice->currency,
            'number' => (string) $note->credit_note_number,
            'inclusive' => $inclusive,
            'extra' => [
                'receiptNotes' => mb_substr(trim((string) $note->reason), 0, 1000),
                'creditDebitNote' => [
                    'receiptID' => $original->fdms_receipt_id,
                    'deviceID' => $original->device->device_id,
                    'receiptGlobalNo' => $original->receipt_global_no,
                    'fiscalDayNo' => $original->fiscal_day_no,
                ],
            ],
        ], $body, $moneyType);
    }

    /**
     * @return array{name: string, price: float, quantity: float, tax: array<string, mixed>, hs_code: ?string, discount_cents: int}
     */
    private function item(FdmsDevice $device, InvoiceLineItem $line, float $quantity, int $discountCents): array
    {
        return [
            'name' => (string) $line->description,
            'price' => (float) $line->unit_price,
            'quantity' => $quantity,
            'tax' => $this->taxFor($device, $line->metadata['fdms_tax_id'] ?? null, (float) $line->tax_rate),
            'hs_code' => isset($line->metadata['hs_code']) ? (string) $line->metadata['hs_code'] : null,
            'discount_cents' => $discountCents,
        ];
    }

    /**
     * @param  array{currency: string, number: string, inclusive: bool, extra: array<string, mixed>}  $head
     * @param  array{lines: list<array<string, mixed>>, taxes: list<array<string, mixed>>, total_cents: int}  $body
     */
    private function store(
        string $tenantId,
        FdmsDevice $device,
        int $deviceId,
        string $type,
        string $invoiceId,
        ?string $creditNoteId,
        array $head,
        array $body,
        string $moneyType,
    ): FdmsReceipt {
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
        $total = $body['total_cents'] / 100;
        $payload = [
            'receiptType' => $type,
            'receiptCurrency' => strtoupper(trim($head['currency'])),
            'receiptCounter' => $counter,
            'receiptGlobalNo' => $globalNo,
            'invoiceNo' => $head['number'],
            ...$head['extra'],
            'receiptDate' => $date->format('Y-m-d\TH:i:s'),
            'receiptLinesTaxInclusive' => $head['inclusive'],
            'receiptLines' => $body['lines'],
            'receiptTaxes' => $body['taxes'],
            'receiptPayments' => [['moneyTypeCode' => $moneyType, 'paymentAmount' => $total]],
            'receiptTotal' => $total,
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
            'invoice_id' => $invoiceId,
            'credit_note_id' => $creditNoteId,
            'receipt_type' => $type,
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
    private function accept(string $tenantId, FdmsDevice $device, FdmsReceipt $receipt, array $answer): void
    {
        $payload = $receipt->payload;
        $receipt->update([
            'status' => 'accepted',
            'fdms_receipt_id' => isset($answer['receiptID']) ? (int) $answer['receiptID'] : null,
            'operation_id' => $answer['operationID'] ?? null,
            'server_date' => isset($answer['serverDate']) ? str_replace('T', ' ', substr((string) $answer['serverDate'], 0, 19)) : null,
            'validation_errors' => $answer['validationErrors'] ?? [],
        ]);

        // Credit note amounts are already negative, so their counters decrease as the spec requires.
        [$salesCounter, $taxCounter] = $payload['receiptType'] === 'CreditNote'
            ? ['CreditNoteByTax', 'CreditNoteTaxByTax']
            : ['SaleByTax', 'SaleTaxByTax'];
        $counters = $device->counters ?? [];
        $currency = $payload['receiptCurrency'];
        foreach ($payload['receiptTaxes'] as $tax) {
            $percent = $tax['taxPercent'] ?? null;
            $this->bump($counters, $salesCounter, $currency, $tax['taxID'], $percent, null, FdmsSigner::cents($tax['salesAmountWithTax']));
            $this->bump($counters, $taxCounter, $currency, $tax['taxID'], $percent, null, FdmsSigner::cents($tax['taxAmount']));
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

        $event = ['fdms_receipt_id' => $receipt->id, 'invoice_id' => $receipt->invoice_id];
        if ($receipt->credit_note_id !== null) {
            $event['credit_note_id'] = $receipt->credit_note_id;
        }
        $event['status'] = 'accepted';
        $this->eventStore->append($tenantId, 'fdms_receipt', $receipt->id, 'enterprise.fiscal.fdms_accepted', $event);
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
