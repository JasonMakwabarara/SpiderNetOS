<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Requires Postgres (jsonb) — not runnable on the sqlite CiFast lane.
 * Covers the recurring platform-plan subscription flow: subscribe checkout +
 * the subscription.* webhook reconciliation into tenant_subscriptions.
 */
class SubscriptionFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $rawSecret = 'sub-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Plan::create([
            'id' => 'growth', 'name' => 'Growth', 'monthly_fee_cents' => 49900,
            'included_usage_cents' => 15000, 'usage_margin_pct' => 15, 'is_custom' => false,
            'dodo_product_id' => 'prod_growth_test',
            'entitlements' => ['agents' => 10, 'flows' => 50, 'seats' => 10, 'pack_slots' => 3],
            'is_active' => true, 'sort' => 2,
        ]);

        config()->set('services.dodo', [
            'api_key' => 'test_key',
            'webhook_secret' => 'whsec_'.base64_encode($this->rawSecret),
            'environment' => 'test',
            'products' => [],
        ]);
    }

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(), 'name' => 'Sub Co', 'slug' => 'sub-'.Str::lower(Str::random(8)),
            'status' => 'active', 'plan' => 'launch', 'onboarding_completed_at' => now(),
        ]);
    }

    private function makeUser(Tenant $tenant): User
    {
        return User::create([
            'name' => 'Owner', 'email' => 'owner@'.Str::lower(Str::random(8)).'.test',
            'password' => bcrypt('password'), 'tenant_id' => $tenant->id, 'role' => 'admin',
            'onboarding_completed_at' => now(), 'step_up_at' => now(),
        ]);
    }

    /** @return array<string,string> raw HTTP server vars for a signed webhook body. */
    private function signedServer(string $id, string $body): array
    {
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", $this->rawSecret, true));

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_WEBHOOK_ID' => $id,
            'HTTP_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_WEBHOOK_SIGNATURE' => "v1,{$sig}",
        ];
    }

    public function test_subscribe_starts_checkout_and_records_pending(): void
    {
        Http::fake(['*/subscriptions' => Http::response(['subscription_id' => 'sub_x', 'checkout_url' => 'https://pay.test/x'], 200)]);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/billing/subscribe', ['plan_id' => 'growth']);

        $res->assertOk()->assertJsonPath('data.checkout_url', 'https://pay.test/x');
        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenant->id, 'plan_id' => 'growth', 'status' => 'pending',
        ]);
    }

    public function test_subscription_active_webhook_activates_and_syncs_plan(): void
    {
        $tenant = $this->makeTenant();
        $sub = TenantSubscription::create(['tenant_id' => $tenant->id, 'plan_id' => 'growth', 'status' => 'pending']);

        $body = json_encode([
            'type' => 'subscription.active',
            'data' => [
                'subscription_id' => 'sub_x',
                'customer' => ['customer_id' => 'cus_1'],
                'next_billing_date' => now()->addMonth()->toIso8601String(),
                'metadata' => ['tenant_subscription_id' => $sub->id],
            ],
        ]);

        $res = $this->call('POST', '/api/webhooks/dodo', [], [], [], $this->signedServer('wh_sub_1', $body), $body);
        $res->assertOk();

        $this->assertDatabaseHas('tenant_subscriptions', [
            'id' => $sub->id, 'status' => 'active', 'dodo_subscription_id' => 'sub_x', 'dodo_customer_id' => 'cus_1',
        ]);
        // plan mirrored onto the tenant for legacy reads
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'plan' => 'growth']);

        // replay is idempotent
        $replay = $this->call('POST', '/api/webhooks/dodo', [], [], [], $this->signedServer('wh_sub_1', $body), $body);
        $replay->assertOk()->assertJsonPath('duplicate', true);
    }

    public function test_cancelled_webhook_marks_cancelled(): void
    {
        $tenant = $this->makeTenant();
        $sub = TenantSubscription::create([
            'tenant_id' => $tenant->id, 'plan_id' => 'growth', 'status' => 'active', 'dodo_subscription_id' => 'sub_y',
        ]);

        $body = json_encode([
            'type' => 'subscription.cancelled',
            'data' => ['subscription_id' => 'sub_y', 'metadata' => ['tenant_subscription_id' => $sub->id]],
        ]);

        $this->call('POST', '/api/webhooks/dodo', [], [], [], $this->signedServer('wh_sub_2', $body), $body)->assertOk();

        $this->assertDatabaseHas('tenant_subscriptions', ['id' => $sub->id, 'status' => 'cancelled']);
    }
}
