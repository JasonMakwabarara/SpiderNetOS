<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Services\TenantKeyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Requires Postgres. Connector catalogue → connect → verify → execute →
 * disconnect, plus the secret storage that previously did not exist.
 */
class ConnectorLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private function user(): User
    {
        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Conn Co', 'slug' => 'conn-'.Str::lower(Str::random(8)),
            'status' => 'active', 'plan' => 'launch', 'onboarding_completed_at' => now(),
        ]);

        return User::create([
            'name' => 'U', 'email' => 'c@'.Str::lower(Str::random(8)).'.test',
            'password' => bcrypt('pw'), 'tenant_id' => $this->tenant->id, 'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]);
    }

    public function test_secret_store_roundtrip_and_forget(): void
    {
        $user = $this->user();
        $keys = app(TenantKeyManager::class);

        $ref = $keys->storeSecret($this->tenant->id, 'integration.slack', json_encode(['bot_token' => 'xoxb-secret']));
        $this->assertSame('integration.slack', $ref);
        $this->assertSame('xoxb-secret', json_decode((string) $keys->getSecret($this->tenant->id, $ref), true)['bot_token']);

        // Stored encrypted, never plaintext.
        $stored = \Illuminate\Support\Facades\DB::table('tenant_secrets')
            ->where('tenant_id', $this->tenant->id)->where('key_name', $ref)->where('active', 1)->value('secret_value');
        $this->assertStringNotContainsString('xoxb-secret', (string) $stored);

        $keys->forgetSecret($this->tenant->id, $ref);
        $this->assertNull($keys->getSecret($this->tenant->id, $ref));
    }

    public function test_catalogue_lists_connectors_with_connection_state(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/integrations/catalogue');
        $res->assertOk();

        $providers = array_column($res->json('data'), 'provider');
        foreach (['slack', 'webhook', 'http_api', 'google_calendar', 'hubspot'] as $p) {
            $this->assertContains($p, $providers);
        }
        // Nothing connected yet.
        $slack = collect($res->json('data'))->firstWhere('provider', 'slack');
        $this->assertFalse($slack['connected']);
        $this->assertSame('not_connected', $slack['status']);
        // Field metadata drives the connect form.
        $this->assertNotEmpty($slack['fields']);
    }

    public function test_connect_verifies_and_stores_then_executes_and_disconnects(): void
    {
        $user = $this->user();

        Http::fake([
            'slack.com/api/auth.test' => Http::response(['ok' => true, 'team' => 'Acme', 'user' => 'spiderbot'], 200),
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '123.456', 'channel' => 'C1'], 200),
        ]);

        // Connect — credentials verified immediately.
        $this->actingAs($user, 'sanctum')->postJson('/api/integrations/slack/authorize', [
            'credentials' => ['bot_token' => 'xoxb-test', 'default_channel' => 'C1'],
        ])->assertCreated()->assertJsonPath('verified', true);

        $integration = TenantIntegration::forTenant($this->tenant->id)->where('provider', 'slack')->firstOrFail();
        $this->assertSame('connected', $integration->status);
        $this->assertSame('messaging', $integration->type);
        $this->assertNotNull($integration->last_verified_at);

        // Execute a declared action.
        $this->actingAs($user, 'sanctum')->postJson('/api/integrations/slack/actions/post_message', [
            'params' => ['channel' => 'C1', 'text' => 'Daily brief ready'],
        ])->assertOk()->assertJsonPath('success', true);

        // Undeclared actions are refused before touching the provider.
        $this->actingAs($user, 'sanctum')->postJson('/api/integrations/slack/actions/delete_workspace', [])
            ->assertStatus(422)->assertJsonPath('success', false);

        // Disconnect removes the row and the secret.
        $this->actingAs($user, 'sanctum')->deleteJson('/api/integrations/slack')->assertOk();
        $this->assertSame(0, TenantIntegration::forTenant($this->tenant->id)->where('provider', 'slack')->count());
        $this->assertNull(app(TenantKeyManager::class)->getSecret($this->tenant->id, 'integration.slack'));
    }

    public function test_missing_required_fields_rejected(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->postJson('/api/integrations/slack/authorize', [
            'credentials' => ['default_channel' => 'C1'], // bot_token missing
        ])->assertStatus(422)->assertJsonPath('missing_fields.0', 'bot_token');
    }

    public function test_unknown_connector_rejected(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->postJson('/api/integrations/not_a_tool/authorize', [
            'credentials' => ['x' => 'y'],
        ])->assertStatus(404);
    }

    public function test_webhook_connector_blocks_non_https_and_private_targets(): void
    {
        $user = $this->user();

        foreach (['http://example.com/hook', 'https://127.0.0.1/hook', 'https://localhost/hook'] as $bad) {
            $res = $this->actingAs($user, 'sanctum')->postJson('/api/integrations/webhook/authorize', [
                'credentials' => ['url' => $bad],
            ]);
            // Stored, but verification must fail (SSRF / plaintext guard).
            $res->assertCreated()->assertJsonPath('verified', false);
        }
    }
}
