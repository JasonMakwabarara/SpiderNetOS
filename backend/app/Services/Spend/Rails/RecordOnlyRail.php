<?php

declare(strict_types=1);

namespace App\Services\Spend\Rails;

use App\Models\PaymentInstruction;
use Illuminate\Support\Str;

/**
 * The V1 default rail: no money moves. The disbursement is considered
 * settled the moment it is recorded — the actual transfer happens outside
 * the system (bank portal, manual transfer) and the admin marks the bill
 * paid with the bank reference.
 */
class RecordOnlyRail implements PaymentRailInterface
{
    public function name(): string
    {
        return 'record_only';
    }

    public function createDisbursement(PaymentInstruction $instruction): array
    {
        return [
            'external_reference' => 'manual-'.Str::uuid(),
            'status' => 'settled',
        ];
    }

    public function getDisbursementStatus(string $externalReference): array
    {
        return [
            'external_reference' => $externalReference,
            'status' => 'settled',
        ];
    }

    public function cancelDisbursement(string $externalReference): array
    {
        return [
            'external_reference' => $externalReference,
            'status' => 'cancelled',
        ];
    }

    public function supportsCurrency(string $currency): bool
    {
        return true;
    }
}
