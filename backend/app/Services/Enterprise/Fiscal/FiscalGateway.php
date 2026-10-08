<?php

declare(strict_types=1);

namespace App\Services\Enterprise\Fiscal;

use App\Models\FiscalSubmission;
use App\Models\Invoice;

interface FiscalGateway
{
    /**
     * @return array{verification_code: string, qr_payload: array, is_live: bool, driver: string}
     */
    public function submit(FiscalSubmission $submission, Invoice $invoice): array;
}
