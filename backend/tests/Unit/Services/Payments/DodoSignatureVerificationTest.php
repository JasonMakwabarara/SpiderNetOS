<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payments;

use App\Services\Payments\DodoPaymentsService;
use Tests\TestCase;

class DodoSignatureVerificationTest extends TestCase
{
    private const RAW_KEY = 'spidernet-test-webhook-key';

    private DodoPaymentsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dodo.webhook_secret', 'whsec_'.base64_encode(self::RAW_KEY));
        config()->set('dodo.webhook_tolerance_seconds', 300);

        $this->service = new DodoPaymentsService;
    }

    private function sign(string $payload, string $webhookId, string $timestamp): string
    {
        return 'v1,'.base64_encode(
            hash_hmac('sha256', "{$webhookId}.{$timestamp}.{$payload}", self::RAW_KEY, true)
        );
    }

    public function test_valid_signature_passes(): void
    {
        $payload = '{"type":"payment.succeeded"}';
        $webhookId = 'msg_123';
        $timestamp = (string) time();

        $this->assertTrue($this->service->verifyWebhookSignature(
            $payload,
            $webhookId,
            $timestamp,
            $this->sign($payload, $webhookId, $timestamp),
        ));
    }

    public function test_valid_signature_in_multi_signature_header_passes(): void
    {
        $payload = '{"type":"subscription.active"}';
        $webhookId = 'msg_456';
        $timestamp = (string) time();

        $header = 'v1,'.base64_encode('bogus').' '.$this->sign($payload, $webhookId, $timestamp);

        $this->assertTrue($this->service->verifyWebhookSignature($payload, $webhookId, $timestamp, $header));
    }

    public function test_tampered_payload_fails(): void
    {
        $webhookId = 'msg_789';
        $timestamp = (string) time();
        $signature = $this->sign('{"amount":100}', $webhookId, $timestamp);

        $this->assertFalse($this->service->verifyWebhookSignature('{"amount":99999}', $webhookId, $timestamp, $signature));
    }

    public function test_stale_timestamp_fails(): void
    {
        $payload = '{"type":"payment.succeeded"}';
        $webhookId = 'msg_old';
        $timestamp = (string) (time() - 3600);

        $this->assertFalse($this->service->verifyWebhookSignature(
            $payload,
            $webhookId,
            $timestamp,
            $this->sign($payload, $webhookId, $timestamp),
        ));
    }

    public function test_missing_secret_fails_closed(): void
    {
        config()->set('dodo.webhook_secret', '');

        $payload = '{"type":"payment.succeeded"}';
        $webhookId = 'msg_nosecret';
        $timestamp = (string) time();

        $this->assertFalse($this->service->verifyWebhookSignature(
            $payload,
            $webhookId,
            $timestamp,
            $this->sign($payload, $webhookId, $timestamp),
        ));
    }
}
