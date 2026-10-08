<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\DomainException;
use App\Services\Enterprise\Fiscal\FdmsException;
use App\Services\Enterprise\Fiscal\FdmsRegistrationService;
use Illuminate\Console\Command;

class FdmsRegister extends Command
{
    protected $signature = 'fdms:register
        {activation_key : 8-character activation key from the FDMS portal (single use)}
        {--serial= : Device serial number; defaults to FDMS_DEVICE_SERIAL_NO}
        {--force : Replace an existing device key and certificate}
        {--yes : Skip the taxpayer confirmation prompt}';

    protected $description = 'Register the configured fiscal device with ZIMRA FDMS and store its key and certificate';

    public function handle(FdmsRegistrationService $registration): int
    {
        $activationKey = trim((string) $this->argument('activation_key'));
        $serial = trim((string) ($this->option('serial') ?: config('fiscal.fdms.serial_no')));
        if (strlen($activationKey) !== 8) {
            $this->error('The activation key must be 8 characters.');

            return self::FAILURE;
        }
        if ($serial === '') {
            $this->error('Pass --serial or set FDMS_DEVICE_SERIAL_NO.');

            return self::FAILURE;
        }

        try {
            $taxpayer = $registration->verify($activationKey, $serial);
            $this->line('Taxpayer: '.($taxpayer['taxPayerName'] ?? '?').' (TIN '.($taxpayer['taxPayerTIN'] ?? '?').')');
            $this->line('VAT number: '.($taxpayer['vatNumber'] ?? 'not a VAT payer'));
            $this->line('Branch: '.($taxpayer['deviceBranchName'] ?? '?'));
            if (! $this->option('yes') && ! $this->confirm('Register device '.config('fiscal.fdms.device_id').' to this taxpayer?')) {
                $this->warn('Not registered.');

                return self::FAILURE;
            }

            $result = $registration->register($activationKey, $serial, (bool) $this->option('force'));
        } catch (FdmsException|DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Device registered as '.$result['common_name'].'.');
        $this->line('Key: '.$result['key_path']);
        $this->line('Certificate: '.$result['cert_path']);
        if (! config('fiscal.fdms.key_path') || ! config('fiscal.fdms.cert_path')) {
            $this->line('Set FDMS_KEY_PATH and FDMS_CERT_PATH to these paths, then FDMS_LIVE=true.');
        }

        return self::SUCCESS;
    }
}
