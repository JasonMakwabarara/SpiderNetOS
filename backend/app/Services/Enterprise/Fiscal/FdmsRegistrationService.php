<?php

declare(strict_types=1);

namespace App\Services\Enterprise\Fiscal;

use App\Exceptions\DomainException;
use OpenSSLAsymmetricKey;

/**
 * Device registration (spec 4.1 to 4.3). The device key is generated here and
 * never leaves the server; ZIMRA signs a CSR whose CN is
 * ZIMRA-<serial>-<10-digit device id> and returns the device certificate.
 */
class FdmsRegistrationService
{
    /** @return array<string, mixed> */
    public function verify(string $activationKey, string $serialNo): array
    {
        return $this->client()->verifyTaxpayerInformation($activationKey, $serialNo);
    }

    /**
     * @return array{key_path: string, cert_path: string, common_name: string, operation_id: ?string}
     */
    public function register(string $activationKey, string $serialNo, bool $replace = false): array
    {
        $client = $this->client();
        [$keyPath, $certPath] = $this->paths();
        if (! $replace && (is_file($keyPath) || is_file($certPath))) {
            throw new DomainException('A device key or certificate already exists. Pass --force to replace it.');
        }

        $key = openssl_pkey_new($this->opensslOptions(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']));
        if ($key === false) {
            throw new DomainException('The device key could not be generated.');
        }
        $commonName = self::commonName($serialNo, $this->deviceId());
        $csr = $this->csr($key, $commonName);

        // The activation key is single use: keep the private key on disk before ZIMRA answers.
        $this->writeKey($keyPath, $key);
        try {
            $answer = $client->registerDevice($activationKey, $csr);
        } catch (FdmsException $e) {
            if (! $e->outcomeUnknown) {
                @unlink($keyPath);
            }
            throw $e;
        }

        $this->writeCertificate($certPath, $answer, $key);

        return [
            'key_path' => $keyPath,
            'cert_path' => $certPath,
            'common_name' => $commonName,
            'operation_id' => isset($answer['operationID']) ? (string) $answer['operationID'] : null,
        ];
    }

    /**
     * Reissue the certificate for the existing key, keeping the old certificate as a backup.
     *
     * @return array{cert_path: string, backup_path: string, operation_id: ?string}
     */
    public function renew(string $serialNo): array
    {
        [$keyPath, $certPath] = $this->paths();
        if (! is_readable($keyPath) || ! is_readable($certPath)) {
            throw new DomainException('Renewal needs the current device key and certificate.');
        }
        $key = openssl_pkey_get_private((string) file_get_contents($keyPath), (string) config('fiscal.fdms.key_passphrase'));
        if ($key === false) {
            throw new DomainException('The FDMS device private key could not be read.');
        }

        $answer = $this->client($keyPath, $certPath)
            ->issueCertificate($this->csr($key, self::commonName($serialNo, $this->deviceId())));

        $backup = $certPath.'.'.date('YmdHis').'.bak';
        if (! copy($certPath, $backup)) {
            throw new DomainException('The current certificate could not be backed up.');
        }
        $this->writeCertificate($certPath, $answer, $key);

        return [
            'cert_path' => $certPath,
            'backup_path' => $backup,
            'operation_id' => isset($answer['operationID']) ? (string) $answer['operationID'] : null,
        ];
    }

    public static function commonName(string $serialNo, int $deviceId): string
    {
        return 'ZIMRA-'.$serialNo.'-'.str_pad((string) $deviceId, 10, '0', STR_PAD_LEFT);
    }

    /** @return array{0: string, 1: string} */
    public function paths(): array
    {
        $deviceId = $this->deviceId();

        return [
            (string) (config('fiscal.fdms.key_path') ?: storage_path('app/fdms/device-'.$deviceId.'.key')),
            (string) (config('fiscal.fdms.cert_path') ?: storage_path('app/fdms/device-'.$deviceId.'.pem')),
        ];
    }

    private function csr(OpenSSLAsymmetricKey $key, string $commonName): string
    {
        $csr = openssl_csr_new(['commonName' => $commonName], $key, $this->opensslOptions(['digest_alg' => 'sha256']));
        if ($csr === false || $csr === true || ! openssl_csr_export($csr, $pem)) {
            throw new DomainException('The certificate request could not be built.');
        }

        return $pem;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function opensslOptions(array $options): array
    {
        $conf = config('fiscal.fdms.openssl_conf');

        return $conf ? ['config' => (string) $conf] + $options : $options;
    }

    private function writeKey(string $path, OpenSSLAsymmetricKey $key): void
    {
        $passphrase = config('fiscal.fdms.key_passphrase');
        if (! openssl_pkey_export($key, $pem, $passphrase ?: null, $this->opensslOptions([]))) {
            throw new DomainException('The device key could not be exported.');
        }
        $this->write($path, $pem, 0600);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function writeCertificate(string $path, array $answer, OpenSSLAsymmetricKey $key): void
    {
        $pem = (string) ($answer['certificate'] ?? '');
        $certificate = $pem === '' ? false : openssl_x509_read($pem);
        if ($certificate === false || ! openssl_x509_check_private_key($certificate, $key)) {
            throw new DomainException('FDMS returned a certificate that does not match the device key.');
        }
        $this->write($path, $pem, 0644);
    }

    private function write(string $path, string $contents, int $mode): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new DomainException('The FDMS key directory could not be created.');
        }
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new DomainException('Could not write '.basename($path).'.');
        }
        @chmod($path, $mode);
    }

    private function client(string $keyPath = '', string $certPath = ''): FdmsClient
    {
        $c = (array) config('fiscal.fdms');
        $missing = array_filter(['base_url', 'device_id', 'model_name', 'model_version'], fn ($f) => empty($c[$f]));
        if ($missing !== []) {
            throw new DomainException('FDMS is not configured: '.implode(', ', $missing).'.');
        }

        return new FdmsClient(
            (string) $c['base_url'],
            (int) $c['device_id'],
            (string) $c['model_name'],
            (string) $c['model_version'],
            $certPath,
            $keyPath,
            ($c['key_passphrase'] ?? null) ?: null,
            (int) ($c['timeout'] ?? 30),
        );
    }

    private function deviceId(): int
    {
        $deviceId = (int) config('fiscal.fdms.device_id');
        if ($deviceId <= 0) {
            throw new DomainException('FDMS is not configured: device_id.');
        }

        return $deviceId;
    }
}
