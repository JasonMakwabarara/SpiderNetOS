<?php

declare(strict_types=1);

namespace App\Services\Spend\Rails;

use App\Models\PaymentInstruction;

/**
 * A payment rail executes (or records) the disbursement described by a
 * PaymentInstruction. V1 ships record_only (no money movement); real rails
 * (Dodo Payments payouts) plug in behind the same interface.
 */
interface PaymentRailInterface
{
    /** Stable machine name, stored on payment_instructions.rail. */
    public function name(): string;

    /**
     * Execute (or record) the disbursement.
     *
     * @return array{external_reference: string, status: string}
     */
    public function createDisbursement(PaymentInstruction $instruction): array;

    /**
     * Look up the rail-side status of a previously created disbursement.
     *
     * @return array{external_reference: string, status: string}
     */
    public function getDisbursementStatus(string $externalReference): array;

    /**
     * Cancel a not-yet-settled disbursement on the rail.
     *
     * @return array{external_reference: string, status: string}
     */
    public function cancelDisbursement(string $externalReference): array;

    /** Whether this rail can pay out in the given ISO currency code. */
    public function supportsCurrency(string $currency): bool;
}
