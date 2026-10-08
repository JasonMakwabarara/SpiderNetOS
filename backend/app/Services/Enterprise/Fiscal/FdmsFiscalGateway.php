<?php

declare(strict_types=1);

namespace App\Services\Enterprise\Fiscal;

use App\Exceptions\DomainException;
use App\Models\FiscalSubmission;
use App\Models\Invoice;

/**
 * Live ZIMRA FDMS transport is intentionally unimplemented.
 * This class must not open a network connection.
 */
class FdmsFiscalGateway implements FiscalGateway
{
    public function submit(FiscalSubmission $submission, Invoice $invoice): array
    {
        throw new DomainException('Live FDMS transport is not available in this release.');
    }
}
