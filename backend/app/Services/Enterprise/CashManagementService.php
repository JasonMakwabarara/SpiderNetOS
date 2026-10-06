<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;
use App\Models\Cashbook;
use App\Models\CashMovement;
use App\Models\Invoice;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;

class CashManagementService
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly PayablesService $payables,
    ) {}

    public function createCashbook(string $tenantId, string $name, string $currency): Cashbook
    {
        return Cashbook::create([
            'tenant_id' => $tenantId,
            'name' => trim($name),
            'currency' => Money::code($currency),
            'status' => 'active',
        ]);
    }

    public function recordMovement(string $tenantId, array $data): CashMovement
    {
        if (! in_array($data['type'], ['receipt', 'payment'], true)) {
            throw new DomainException('Cash movement type must be receipt or payment.');
        }

        return DB::transaction(function () use ($tenantId, $data) {
            $cashbook = Cashbook::forTenant($tenantId)->lockForUpdate()->findOrFail($data['cashbook_id']);
            $currency = Money::code($data['currency']);
            if ($currency !== $cashbook->currency) {
                throw new DomainException('Movement currency must match the cashbook currency.');
            }

            $amount = Money::positive($data['amount']);
            $invoice = null;
            if (! empty($data['invoice_id'])) {
                $invoice = Invoice::forTenant($tenantId)->findOrFail($data['invoice_id']);
            }

            if ($data['type'] === 'payment' && $invoice !== null) {
                $this->payables->settle($tenantId, $invoice, $amount);
            }

            $movement = CashMovement::create([
                'tenant_id' => $tenantId,
                'cashbook_id' => $cashbook->id,
                'invoice_id' => $invoice?->id,
                'type' => $data['type'],
                'amount' => $amount,
                'currency' => $currency,
                'movement_date' => $data['movement_date'],
            ]);

            $this->eventStore->append($tenantId, 'cash_movement', $movement->id, 'enterprise.cash.recorded', [
                'cash_movement_id' => $movement->id,
                'cashbook_id' => $cashbook->id,
                'invoice_id' => $movement->invoice_id,
                'type' => $movement->type,
            ]);

            return $movement;
        });
    }
}
