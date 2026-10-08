<?php

declare(strict_types=1);

namespace App\Services\Enterprise\Fiscal;

use App\Models\FiscalSubmission;
use App\Models\Invoice;

class SandboxFiscalGateway implements FiscalGateway
{
    public function submit(FiscalSubmission $submission, Invoice $invoice): array
    {
        $code = 'SANDBOX-'.$submission->id;

        return [
            'verification_code' => $code,
            'qr_payload' => [
                'driver' => 'sandbox',
                'verification_code' => $code,
                'is_live' => false,
                'invoice_id' => $invoice->id,
            ],
            'is_live' => false,
            'driver' => 'sandbox',
        ];
    }
}
