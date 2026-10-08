<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\Cashbook;
use App\Models\CashMovement;
use App\Models\Invoice;
use App\Services\EventStore;

class CashManagementService
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function createCashbook(string $tenantId, string $name, string $currency): Cashbook
    {
        return Cashbook::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'currency' => Money::code($currency),
        ]);
    }

    public function recordMovement(string $tenantId, array $data): CashMovement
    {
        if (! in_array($data['type'], ['receipt', 'payment'], true)) {
            throw new DomainException('Cash movement type must be receipt or payment.');
        }

        $cashbook = Cashbook::forTenant($tenantId)->findOrFail($data['cashbook_id']);
        $currency = Money::code($data['currency']);
        if ($currency !== $cashbook->currency) {
            throw new DomainException('Movement currency must match the cashbook currency.');
        }

        $amount = Money::positive($data['amount']);

        if (! empty($data['invoice_id'])) {
            Invoice::forTenant($tenantId)->findOrFail($data['invoice_id']);
        }

        $movement = CashMovement::create([
            'tenant_id' => $tenantId,
            'cashbook_id' => $cashbook->id,
            'type' => $data['type'],
            'amount' => $amount,
            'currency' => $currency,
            'movement_date' => $data['movement_date'],
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'] ?? null,
            'invoice_id' => $data['invoice_id'] ?? null,
        ]);

        $event = $data['type'] === 'receipt'
            ? 'enterprise.cash.receipt_recorded'
            : 'enterprise.cash.payment_recorded';

        $this->eventStore->append($tenantId, 'cash_movement', $movement->id, $event, [
            'cash_movement_id' => $movement->id,
            'cashbook_id' => $cashbook->id,
            'type' => $movement->type,
            'amount' => $amount,
            'currency' => $currency,
            'invoice_id' => $movement->invoice_id,
        ]);

        return $movement;
    }
}
