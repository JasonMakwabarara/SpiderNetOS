<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Dodo Payments HTTP client (no SDK dependency).
 *
 * Ported from the proven Hannah AI DodoPaymentsService: checkout sessions
 * carry tenant/plan identifiers in metadata so webhook fulfillment never
 * guesses, and webhook verification implements the Standard Webhooks spec
 * Dodo uses (webhook-id.webhook-timestamp.body signed with HMAC-SHA256,
 * whsec_-prefixed base64 secret, "v1,<sig>" signature list).
 */
class DodoPaymentsService
{
    public const GATEWAY_CODE = 'dodo';

    public function enabled(): bool
    {
        return (bool) config('dodo.enabled') && config('dodo.api_key') !== '';
    }

    /**
     * Create a hosted checkout session for a tenant plan upgrade.
     *
     * @return array{checkout_url: string, session_id: ?string}
     */
    public function createCheckoutSession(Tenant $tenant, User $user, string $planId): array
    {
        $plan = $this->plan($planId);

        if (($plan['product_id'] ?? '') === '') {
            throw new \RuntimeException("Dodo product ID is not configured for plan [{$planId}].");
        }

        $reference = 'SNOS-' . strtoupper(Str::random(12));

        $response = $this->client()->post('/checkouts', [
            'product_cart' => [
                ['product_id' => $plan['product_id'], 'quantity' => 1],
            ],
            'customer' => [
                'email' => $user->email,
                'name' => $user->name,
            ],
            'return_url' => config('dodo.return_url') . '?reference=' . urlencode($reference),
            'metadata' => [
                'reference' => $reference,
                'tenant_id' => (string) $tenant->id,
                'user_id' => (string) $user->id,
                'plan_id' => $planId,
                'gateway' => self::GATEWAY_CODE,
            ],
        ]);

        if ($response->failed()) {
            Log::error('dodo-> createCheckoutSession(): API error', [
                'status' => $response->status(),
                'tenant_id' => (string) $tenant->id,
                'plan_id' => $planId,
            ]);

            throw new \RuntimeException('Dodo Payments checkout could not be created.');
        }

        $checkoutUrl = $response->json('checkout_url') ?? $response->json('url');

        if (! is_string($checkoutUrl) || $checkoutUrl === '') {
            throw new \RuntimeException('Dodo Payments did not return a checkout URL.');
        }

        return [
            'checkout_url' => $checkoutUrl,
            'session_id' => $response->json('session_id'),
            'reference' => $reference,
        ];
    }

    /**
     * Schedule cancellation at the end of the current billing period.
     */
    public function cancelSubscription(string $providerSubscriptionId): void
    {
        $response = $this->client()->patch('/subscriptions/' . $providerSubscriptionId, [
            'cancel_at_next_billing_date' => true,
        ]);

        if ($response->failed()) {
            Log::error('dodo-> cancelSubscription(): API error', [
                'status' => $response->status(),
                'subscription_id' => $providerSubscriptionId,
            ]);

            throw new \RuntimeException('Dodo Payments subscription could not be cancelled.');
        }
    }

    /**
     * Verify a Standard Webhooks signature. Returns true only when the
     * timestamp is within tolerance and one of the v1 signatures matches.
     */
    public function verifyWebhookSignature(string $payload, string $webhookId, string $timestamp, string $signatureHeader): bool
    {
        $secret = (string) config('dodo.webhook_secret');

        if ($secret === '' || $webhookId === '' || $timestamp === '' || $signatureHeader === '') {
            return false;
        }

        if (! ctype_digit($timestamp)) {
            return false;
        }

        $tolerance = (int) config('dodo.webhook_tolerance_seconds', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $key = str_starts_with($secret, 'whsec_')
            ? base64_decode(substr($secret, 6), true)
            : $secret;

        if ($key === false || $key === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', "{$webhookId}.{$timestamp}.{$payload}", $key, true));

        foreach (explode(' ', $signatureHeader) as $candidate) {
            $parts = explode(',', $candidate, 2);
            $signature = $parts[1] ?? $parts[0];

            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{label: string, product_id: string, price_usd: ?float, interval: string, limits: array}
     */
    public function plan(string $planId): array
    {
        $plan = config("dodo.plans.{$planId}");

        if (! is_array($plan)) {
            throw new \InvalidArgumentException("Unknown plan [{$planId}].");
        }

        return $plan;
    }

    /**
     * Plan id for a Dodo product id, or null when unmapped.
     */
    public function planIdForProduct(?string $productId): ?string
    {
        if (! $productId) {
            return null;
        }

        foreach ((array) config('dodo.plans', []) as $planId => $plan) {
            if (($plan['product_id'] ?? null) === $productId) {
                return $planId;
            }
        }

        return null;
    }

    private function client(): PendingRequest
    {
        $mode = config('dodo.mode') === 'live' ? 'live' : 'test';
        $baseUrl = config("dodo.base_urls.{$mode}");

        return Http::withToken((string) config('dodo.api_key'))
            ->baseUrl($baseUrl)
            ->acceptJson()
            ->timeout(15);
    }
}
