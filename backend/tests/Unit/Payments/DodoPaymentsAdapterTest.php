<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use App\Services\Integrations\DodoPaymentsAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Pure-crypto unit tests for DodoPaymentsAdapter::verifyWebhook() — no DB,
 * no HTTP, no Laravel bootstrap. Standard Webhooks spec: HMAC-SHA256 over
 * "{webhook-id}.{webhook-timestamp}.{raw_body}", base64-encoded, compared
 * against the "v1,<sig>" webhook-signature header.
 */
class DodoPaymentsAdapterTest extends TestCase
{
    private function adapter(string $secret): DodoPaymentsAdapter
    {
        return new DodoPaymentsAdapter([
            'api_key' => 'test_key',
            'webhook_secret' => $secret,
            'environment' => 'test',
        ]);
    }

    private function sign(string $secretBytes, string $id, string $timestamp, string $body): string
    {
        return base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $secretBytes, true));
    }

    public function test_valid_signature_verifies(): void
    {
        $secretBytes = 'raw-secret-bytes-for-test';
        $adapter = $this->adapter('whsec_'.base64_encode($secretBytes));

        $body = json_encode(['type' => 'payment.succeeded']);
        $id = 'msg_1';
        $ts = (string) time();
        $sig = $this->sign($secretBytes, $id, $ts, $body);

        $this->assertTrue($adapter->verifyWebhook($body, [
            'webhook-id' => $id,
            'webhook-timestamp' => $ts,
            'webhook-signature' => "v1,{$sig}",
        ]));
    }

    public function test_tampered_body_rejected(): void
    {
        $secretBytes = 'raw-secret-bytes-for-test';
        $adapter = $this->adapter('whsec_'.base64_encode($secretBytes));

        $id = 'msg_2';
        $ts = (string) time();
        $sig = $this->sign($secretBytes, $id, $ts, json_encode(['type' => 'payment.succeeded']));

        // Signature computed over a different body than the one presented.
        $this->assertFalse($adapter->verifyWebhook(json_encode(['type' => 'payment.failed']), [
            'webhook-id' => $id,
            'webhook-timestamp' => $ts,
            'webhook-signature' => "v1,{$sig}",
        ]));
    }

    public function test_wrong_secret_rejected(): void
    {
        $adapter = $this->adapter('whsec_'.base64_encode('correct-secret'));

        $body = json_encode(['type' => 'payment.succeeded']);
        $id = 'msg_3';
        $ts = (string) time();
        $sig = $this->sign('wrong-secret', $id, $ts, $body);

        $this->assertFalse($adapter->verifyWebhook($body, [
            'webhook-id' => $id,
            'webhook-timestamp' => $ts,
            'webhook-signature' => "v1,{$sig}",
        ]));
    }

    public function test_missing_headers_rejected(): void
    {
        $adapter = $this->adapter('whsec_'.base64_encode('secret'));

        $this->assertFalse($adapter->verifyWebhook('{}', ['webhook-id' => 'msg_4']));
        $this->assertFalse($adapter->verifyWebhook('{}', []));
    }

    public function test_raw_secret_without_whsec_prefix_is_used_as_is(): void
    {
        $rawSecret = 'plain-raw-secret-no-prefix';
        $adapter = $this->adapter($rawSecret);

        $body = '{"type":"payment.succeeded"}';
        $id = 'msg_5';
        $ts = (string) time();
        $sig = $this->sign($rawSecret, $id, $ts, $body);

        $this->assertTrue($adapter->verifyWebhook($body, [
            'webhook-id' => $id,
            'webhook-timestamp' => $ts,
            'webhook-signature' => "v1,{$sig}",
        ]));
    }

    public function test_multiple_signatures_in_header_checked_until_match(): void
    {
        $secretBytes = 'raw-secret-bytes-for-test';
        $adapter = $this->adapter('whsec_'.base64_encode($secretBytes));

        $body = '{"type":"payment.succeeded"}';
        $id = 'msg_6';
        $ts = (string) time();
        $correctSig = $this->sign($secretBytes, $id, $ts, $body);

        // Simulates key rotation: an old (wrong) key's signature listed first,
        // the currently-valid one second — both space-separated per spec.
        $this->assertTrue($adapter->verifyWebhook($body, [
            'webhook-id' => $id,
            'webhook-timestamp' => $ts,
            'webhook-signature' => "v1,bm90dGhlcmlnaHRzaWc= v1,{$correctSig}",
        ]));
    }

    public function test_stale_timestamp_rejected(): void
    {
        $secretBytes = 'raw-secret-bytes-for-test';
        $adapter = $this->adapter('whsec_'.base64_encode($secretBytes));

        $body = json_encode(['type' => 'payment.succeeded']);
        $id = 'msg_7';
        // Timestamp well outside the ±300s replay window but otherwise valid.
        $ts = (string) (time() - 3600);
        $sig = $this->sign($secretBytes, $id, $ts, $body);

        $this->assertFalse($adapter->verifyWebhook($body, [
            'webhook-id' => $id,
            'webhook-timestamp' => $ts,
            'webhook-signature' => "v1,{$sig}",
        ]));
    }

    public function test_nonnumeric_timestamp_rejected(): void
    {
        $secretBytes = 'raw-secret-bytes-for-test';
        $adapter = $this->adapter('whsec_'.base64_encode($secretBytes));

        $body = json_encode(['type' => 'payment.succeeded']);
        $sig = $this->sign($secretBytes, 'msg_8', 'not-a-timestamp', $body);

        $this->assertFalse($adapter->verifyWebhook($body, [
            'webhook-id' => 'msg_8',
            'webhook-timestamp' => 'not-a-timestamp',
            'webhook-signature' => "v1,{$sig}",
        ]));
    }

    public function test_missing_api_key_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        new DodoPaymentsAdapter(['api_key' => '', 'webhook_secret' => 'x', 'environment' => 'test']);
    }
}
