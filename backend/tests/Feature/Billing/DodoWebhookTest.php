<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\TenantSubscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Dodo Payments webhook lifecycle:
 * - signature gate (401 on bad signature)
 * - subscription.active → tenant plan + limits + Subscription row
 * - idempotent replay by webhook-id
 * - subscription.cancelled → cancellation recorded, access kept to period end
 */
class DodoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const RAW_KEY = 'spidernet-test-webhook-key';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dodo.enabled', true);
        config()->set('dodo.webhook_secret', 'whsec_' . base64_encode(self::RAW_KEY));
        config()->set('dodo.plans.growth.product_id', 'prod_growth_test');
        // Primary config path used by the routed webhook middleware.
        config()->set('services.dodo.webhook_secret', 'whsec_' . base64_encode(self::RAW_KEY));
    }

    private function createTenant(string $plan = 'starter'): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Billing Test Tenant',
            'slug' => 'billing-test-' . Str::lower(Str::random(6)),
            'status' => 'active',
            'plan' => $plan,
        ]);
    }

    private function postWebhook(array $payload, ?string $webhookId = null, bool $validSignature = true)
    {
        $body = json_encode($payload);
        $webhookId ??= 'msg_' . Str::random(10);
        $timestamp = (string) time();

        $signature = $validSignature
            ? 'v1,' . base64_encode(hash_hmac('sha256', "{$webhookId}.{$timestamp}.{$body}", self::RAW_KEY, true))
            : 'v1,' . base64_encode('forged-signature');

        return $this->call(
            'POST',
            '/api/webhooks/dodo',
            server: [
                'HTTP_webhook-id' => $webhookId,
                'HTTP_webhook-timestamp' => $timestamp,
                'HTTP_webhook-signature' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body,
        );
    }

    private function activationPayload(Tenant $tenant): array
    {
        return [
            'type' => 'subscription.active',
            'data' => [
                'subscription_id' => 'sub_dodo_123',
                'product_id' => 'prod_growth_test',
                'currency' => 'USD',
                'next_billing_date' => now()->addMonth()->toIso8601String(),
                'metadata' => [
                    'tenant_id' => (string) $tenant->id,
                    'plan_id' => 'growth',
                ],
            ],
        ];
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $tenant = $this->createTenant();

        $response = $this->postWebhook($this->activationPayload($tenant), validSignature: false);

        $response->assertStatus(401);
        $this->assertSame('starter', $tenant->fresh()->plan);
    }

    public function test_subscription_active_upgrades_tenant(): void
    {
        $tenant = $this->createTenant();

        $response = $this->postWebhook($this->activationPayload($tenant));

        $response->assertOk()->assertJson(['received' => true]);

        $tenant->refresh();
        $this->assertSame('growth', $tenant->plan);
        $this->assertSame('active', $tenant->status);
        $this->assertNotNull($tenant->subscribed_at);
        $this->assertSame(25, $tenant->limits['agents'] ?? null);

        $subscription = TenantSubscription::query()
            ->where('tenant_id', (string) $tenant->id)
            ->first();

        $this->assertNotNull($subscription);
        $this->assertSame('sub_dodo_123', $subscription->dodo_subscription_id);
        $this->assertSame('active', $subscription->status);

        $this->assertDatabaseHas('event_log', [
            'tenant_id' => (string) $tenant->id,
            'event_type' => 'platform.subscription.active',
        ]);
    }

    public function test_duplicate_webhook_id_is_acknowledged_but_not_reprocessed(): void
    {
        $tenant = $this->createTenant();
        $payload = $this->activationPayload($tenant);

        $this->postWebhook($payload, webhookId: 'msg_fixed_id')->assertOk();

        $response = $this->postWebhook($payload, webhookId: 'msg_fixed_id');

        $response->assertOk()->assertJson(['received' => true, 'duplicate' => true]);
        $this->assertSame(1, TenantSubscription::query()->where('tenant_id', (string) $tenant->id)->count());
    }

    public function test_subscription_cancelled_keeps_access_until_period_end(): void
    {
        $tenant = $this->createTenant();
        $this->postWebhook($this->activationPayload($tenant))->assertOk();

        $response = $this->postWebhook([
            'type' => 'subscription.cancelled',
            'data' => [
                'subscription_id' => 'sub_dodo_123',
                'cancelled_at' => now()->addMonth()->toIso8601String(),
                'metadata' => ['tenant_id' => (string) $tenant->id],
            ],
        ]);

        $response->assertOk();

        $tenant->refresh();
        $this->assertSame('growth', $tenant->plan, 'plan retained until period end');
        $this->assertSame('active', $tenant->status);

        $subscription = TenantSubscription::query()
            ->where('tenant_id', (string) $tenant->id)
            ->first();

        $this->assertSame('cancelled', $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);
    }
}
