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

        if ($this->apiKey === '') {
            throw new \RuntimeException('Dodo Payments API key is not configured (DODO_API_KEY).');
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array{session_id: string, checkout_url: ?string}
     */
    public function createCheckoutSession(string $productId, array $metadata, string $returnUrl, string $cancelUrl): array
    {
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
        $response = Http::withToken($this->apiKey)->get($this->baseUrl.'/payments/'.$paymentId);

        if ($response->failed()) {
            throw new \RuntimeException('Dodo Payments API error: '.$response->body());
        }

        return $response->json();
    }

    public function getSubscription(string $subscriptionId): array
    {
        $response = Http::withToken($this->apiKey)->get($this->baseUrl.'/subscriptions/'.$subscriptionId);

        if ($response->failed()) {
            throw new \RuntimeException('Dodo Payments API error: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Verify a webhook per the Standard Webhooks spec:
     *   signed_content = "{id}.{timestamp}.{raw_body}"
     *   expected       = base64(hmac_sha256(secret_bytes, signed_content))
     *   header format  = "v1,<base64sig>" (space-separated if multiple signing keys)
     *
     * @param array<string, string> $headers Expects webhook-id, webhook-timestamp, webhook-signature (any case).
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
