<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\DomainException;
use App\Services\Enterprise\Fiscal\FdmsException;
use App\Services\Enterprise\Fiscal\FdmsRegistrationService;
use Illuminate\Console\Command;

class FdmsRenewCertificate extends Command
{
    protected $signature = 'fdms:renew-certificate {--serial= : Device serial number; defaults to FDMS_DEVICE_SERIAL_NO}';

    protected $description = 'Reissue the FDMS device certificate for the existing device key';

    public function handle(FdmsRegistrationService $registration): int
    {
        $serial = trim((string) ($this->option('serial') ?: config('fiscal.fdms.serial_no')));
        if ($serial === '') {
            $this->error('Pass --serial or set FDMS_DEVICE_SERIAL_NO.');

            return self::FAILURE;
        }

        try {
            $result = $registration->renew($serial);
        } catch (FdmsException|DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Certificate renewed: '.$result['cert_path']);
        $this->line('Previous certificate kept at '.$result['backup_path']);

        return self::SUCCESS;
    }
}
