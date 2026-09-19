<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;

/**
 * Dodo Payments — merchant of record for purchasable feature packs.
 *
 * Endpoint shapes confirmed against docs.dodopayments.com (2026-07):
 *   base URL      https://test.dodopayments.com | https://live.dodopayments.com
 *   auth          Authorization: Bearer <api_key>
 *   checkout      POST /checkouts -> {session_id, checkout_url, ...}
 *   webhooks      Standard Webhooks spec (webhook-id / webhook-signature / webhook-timestamp)
 *
 * Unlike StripeAdapter (per-tenant credentials, `->1v1/payment_intentions`
 * typo — do not copy), this adapter uses platform-level credentials from
 * config/services.php: packs are sold BY SpiderNetOS, not through a
 * per-tenant connected account.
 */
class DodoPaymentsAdapter
{
    /** Standard Webhooks replay window: reject deliveries whose timestamp is older/newer than this. */
    private const WEBHOOK_TOLERANCE_SECONDS = 300;

    private string $baseUrl;

    private string $apiKey;

    private string $webhookSecret;

    public function __construct(array $config)
    {
        $this->apiKey = $config['api_key'] ?? '';
        $this->webhookSecret = $config['webhook_secret'] ?? '';
        $this->baseUrl = ($config['environment'] ?? 'test') === 'live'
            ? 'https://live.dodopayments.com'
            : 'https://test.dodopayments.com';
    }

    /**
     * API calls need a key; webhook verification does not — so the key is
     * asserted per-call rather than in the constructor.
     */
    private function assertApiKey(): void
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Dodo Payments API key is not configured (DODO_API_KEY).');
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{session_id: string, checkout_url: ?string}
     */
    public function createCheckoutSession(string $productId, array $metadata, string $returnUrl, string $cancelUrl): array
    {
        $this->assertApiKey();

        $response = Http::withToken($this->apiKey)
            ->post($this->baseUrl.'/checkouts', [
                'product_cart' => [['product_id' => $productId, 'quantity' => 1]],
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $metadata,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Dodo Payments checkout error: '.$response->body());
        }

        return $response->json();
    }

    public function getPayment(string $paymentId): array
    {
        $this->assertApiKey();

        $response = Http::withToken($this->apiKey)->get($this->baseUrl.'/payments/'.$paymentId);

        if ($response->failed()) {
            throw new \RuntimeException('Dodo Payments API error: '.$response->body());
        }

        return $response->json();
    }

    public function getSubscription(string $subscriptionId): array
    {
        $this->assertApiKey();

        $response = Http::withToken($this->apiKey)->get($this->baseUrl.'/subscriptions/'.$subscriptionId);

        if ($response->failed()) {
            throw new \RuntimeException('Dodo Payments API error: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Start a recurring subscription checkout for a platform plan.
     *
     * @param  array<string, mixed>  $metadata  carries tenant_id + tenant_subscription_id so the webhook can reconcile.
     * @return array{subscription_id?: string, checkout_url?: string, payment_link?: string}
     */
    public function createSubscriptionCheckout(string $productId, array $metadata, string $returnUrl, string $cancelUrl): array
    {
        $this->assertApiKey();

        $response = Http::withToken($this->apiKey)
            ->post($this->baseUrl.'/subscriptions', [
                'product_id' => $productId,
                'quantity' => 1,
                'payment_link' => true,
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $metadata,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Dodo Payments subscription error: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Cancel a subscription — at period end by default, immediately if $atPeriodEnd is false.
     */
    public function cancelSubscription(string $subscriptionId, bool $atPeriodEnd = true): array
    {
        $this->assertApiKey();

        $response = Http::withToken($this->apiKey)
            ->patch($this->baseUrl.'/subscriptions/'.$subscriptionId, [
                'status' => $atPeriodEnd ? 'cancel_at_next_billing_date' : 'cancelled',
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Dodo Payments cancel error: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Switch an active subscription to a different plan/product (upgrade or downgrade).
     */
    public function changePlan(string $subscriptionId, string $newProductId): array
    {
        $this->assertApiKey();

        $response = Http::withToken($this->apiKey)
            ->post($this->baseUrl.'/subscriptions/'.$subscriptionId.'/change-plan', [
                'product_id' => $newProductId,
                'quantity' => 1,
                'proration_billing_mode' => 'prorated_immediately',
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Dodo Payments change-plan error: '.$response->body());
        }

        return $response->json();
    }

    /**
     * One-off charge against a saved payment method (used to collect metered
     * usage overage at the end of a billing period).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function chargeOverage(string $customerId, int $amountCents, string $currency, array $metadata): array
    {
        $this->assertApiKey();

        $response = Http::withToken($this->apiKey)
            ->post($this->baseUrl.'/payments', [
                'customer' => ['customer_id' => $customerId],
                'amount' => $amountCents,
                'currency' => $currency,
                'metadata' => $metadata,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Dodo Payments overage charge error: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Verify a webhook per the Standard Webhooks spec:
     *   signed_content = "{id}.{timestamp}.{raw_body}"
     *   expected       = base64(hmac_sha256(secret_bytes, signed_content))
     *   header format  = "v1,<base64sig>" (space-separated if multiple signing keys)
     *
     * @param  array<string, string>  $headers  Expects webhook-id, webhook-timestamp, webhook-signature (any case).
     */
    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        if ($this->webhookSecret === '') {
            throw new \RuntimeException('Dodo Payments webhook secret is not configured (DODO_WEBHOOK_SECRET).');
        }

        $headers = array_change_key_case($headers, CASE_LOWER);
        $id = $headers['webhook-id'] ?? null;
        $timestamp = $headers['webhook-timestamp'] ?? null;
        $signatureHeader = $headers['webhook-signature'] ?? null;

        if (! $id || ! $timestamp || ! $signatureHeader) {
            return false;
        }

        // Replay defense (Standard Webhooks): reject timestamps outside the
        // tolerance window even if the signature is otherwise valid, so a
        // captured delivery cannot be replayed indefinitely.
        $ts = filter_var($timestamp, FILTER_VALIDATE_INT);
        if ($ts === false || abs(time() - $ts) > self::WEBHOOK_TOLERANCE_SECONDS) {
            return false;
        }

        // Standard Webhooks secrets are typically "whsec_<base64>"; strip the
        // prefix and base64-decode to get the raw HMAC key. Fall back to
        // using the secret as raw bytes if it isn't in that format.
        $secretKey = str_starts_with($this->webhookSecret, 'whsec_')
            ? base64_decode(substr($this->webhookSecret, 6), true)
            : $this->webhookSecret;

        if ($secretKey === false) {
            return false;
        }

        $signedContent = "{$id}.{$timestamp}.{$rawBody}";
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $secretKey, true));

        foreach (explode(' ', $signatureHeader) as $candidate) {
            $sig = str_contains($candidate, ',') ? explode(',', $candidate, 2)[1] : $candidate;
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
