<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Integrations\StripeAdapter;
use PHPUnit\Framework\TestCase;

class StripeAdapterTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private function adapter(): StripeAdapter
    {
        return new StripeAdapter('tenant-1', ['api_key' => 'sk_test', 'webhook_secret' => self::SECRET]);
    }

    private function sign(string $payload, int $timestamp, string $secret = self::SECRET): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    private function payload(): string
    {
        return (string) json_encode([
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_123', 'amount_received' => 2500]],
        ]);
    }

    public function test_a_correctly_signed_event_is_mapped(): void
    {
        $t = time();
        $result = $this->adapter()->handleWebhook($this->payload(), "t={$t},".$this->sign($this->payload(), $t));

        $this->assertSame(['type' => 'payment_succeeded', 'payment_intent_id' => 'pi_123', 'amount' => 25], $result);
    }

    public function test_any_v1_signature_may_match_while_a_secret_is_rolled(): void
    {
        $t = time();
        $header = "t={$t},".$this->sign($this->payload(), $t, 'whsec_old').','.$this->sign($this->payload(), $t);

        $this->assertSame('payment_succeeded', $this->adapter()->handleWebhook($this->payload(), $header)['type']);
    }

    public function test_a_wrong_signature_is_refused(): void
    {
        $t = time();

        $this->expectExceptionMessage('signature does not match');
        $this->adapter()->handleWebhook($this->payload(), "t={$t},".$this->sign($this->payload(), $t, 'whsec_other'));
    }

    public function test_a_tampered_payload_is_refused(): void
    {
        $t = time();
        $header = "t={$t},".$this->sign($this->payload(), $t);

        $this->expectExceptionMessage('signature does not match');
        $this->adapter()->handleWebhook(str_replace('2500', '9999', $this->payload()), $header);
    }

    public function test_a_stale_timestamp_is_refused(): void
    {
        $t = time() - 301;

        $this->expectExceptionMessage('outside the tolerance');
        $this->adapter()->handleWebhook($this->payload(), "t={$t},".$this->sign($this->payload(), $t));
    }

    public function test_a_malformed_header_is_refused(): void
    {
        $this->expectExceptionMessage('malformed');
        $this->adapter()->handleWebhook($this->payload(), 'garbage');
    }
}
