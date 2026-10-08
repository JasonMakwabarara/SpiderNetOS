<?php

declare(strict_types=1);

namespace App\Services\Enterprise\Fiscal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Fiscal Device Gateway API over mutual TLS, authenticated by the
 * ZIMRA-issued device certificate. SubmitReceipt, OpenDay and CloseDay are
 * never retried here; the caller decides after reading the outcome.
 */
final class FdmsClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $deviceId,
        private readonly string $modelName,
        private readonly string $modelVersion,
        private readonly string $certPath,
        private readonly string $keyPath,
        private readonly ?string $keyPassphrase = null,
        private readonly int $timeout = 30,
    ) {}

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return $this->send('get', 'GetConfig');
    }

    /** @return array<string, mixed> */
    public function getStatus(): array
    {
        return $this->send('get', 'GetStatus');
    }

    /** @return array<string, mixed> */
    public function openDay(int $fiscalDayNo, string $fiscalDayOpened): array
    {
        return $this->send('post', 'OpenDay', [
            'fiscalDayNo' => $fiscalDayNo,
            'fiscalDayOpened' => $fiscalDayOpened,
        ]);
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return array<string, mixed>
     */
    public function submitReceipt(array $receipt): array
    {
        return $this->send('post', 'SubmitReceipt', ['receipt' => $receipt]);
    }

    /**
     * @param  list<array<string, mixed>>  $counters
     * @param  array{hash: string, signature: string}  $signature
     * @return array<string, mixed>
     */
    public function closeDay(int $fiscalDayNo, array $counters, array $signature, int $receiptCounter): array
    {
        return $this->send('post', 'CloseDay', [
            'fiscalDayNo' => $fiscalDayNo,
            'fiscalDayCounters' => $counters,
            'fiscalDayDeviceSignature' => $signature,
            'receiptCounter' => $receiptCounter,
        ]);
    }

    /** @return array<string, mixed> */
    public function verifyTaxpayerInformation(string $activationKey, string $serialNo): array
    {
        return $this->send('post', 'VerifyTaxpayerInformation', [
            'activationKey' => $activationKey,
            'deviceSerialNo' => $serialNo,
        ], public: true);
    }

    /** @return array<string, mixed> */
    public function registerDevice(string $activationKey, string $certificateRequestPem): array
    {
        return $this->send('post', 'RegisterDevice', [
            'activationKey' => $activationKey,
            'certificateRequest' => $certificateRequestPem,
        ], public: true);
    }

    /** @return array<string, mixed> */
    public function issueCertificate(string $certificateRequestPem): array
    {
        return $this->send('post', 'IssueCertificate', ['certificateRequest' => $certificateRequestPem]);
    }

    /**
     * Public endpoints (verify, register) run before a device certificate exists.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $endpoint, array $body = [], bool $public = false): array
    {
        $path = ($public ? '/Public/v1/' : '/Device/v1/').$this->deviceId.'/'.$endpoint;
        $request = $public ? $this->publicRequest() : $this->request();

        try {
            $response = $method === 'get'
                ? $request->get($path)
                : $request->post($path, $body);
        } catch (ConnectionException $e) {
            throw new FdmsException('FDMS could not be reached: '.$e->getMessage(), outcomeUnknown: true);
        }

        if ($response->successful()) {
            return (array) $response->json();
        }

        $problem = (array) $response->json();
        $code = isset($problem['errorCode']) ? (string) $problem['errorCode'] : null;
        $detail = (string) ($problem['detail'] ?? $problem['title'] ?? 'HTTP '.$response->status());

        throw new FdmsException(
            'FDMS refused '.$endpoint.($code ? ' ('.$code.')' : '').': '.$detail,
            httpStatus: $response->status(),
            errorCode: $code,
            outcomeUnknown: $response->serverError(),
        );
    }

    private function request(): PendingRequest
    {
        $cert = $this->keyPassphrase ? [$this->certPath, $this->keyPassphrase] : $this->certPath;
        $key = $this->keyPassphrase ? [$this->keyPath, $this->keyPassphrase] : $this->keyPath;

        return $this->publicRequest()->withOptions([
            'cert' => $cert,
            'ssl_key' => $key,
            'verify' => true,
        ]);
    }

    private function publicRequest(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->withHeaders([
                'DeviceModelName' => $this->modelName,
                'DeviceModelVersionNo' => $this->modelVersion,
            ])
            ->withOptions(['verify' => true]);
    }
}
