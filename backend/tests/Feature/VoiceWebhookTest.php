<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\VoiceCall;
use App\Models\VoiceNumber;
use App\Services\CostGovernor;
use App\Services\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * VoiceWebhookTest — Phase A
 *
 * Tests for inbound/gather/status/recording webhooks focusing on:
 * - Twilio signature verification (bypass in testing env)
 * - Feature flag kill-switch
 * - Cost governor block path
 * - Tenant resolution fallback
 * - Happy-path TwiML response
 */
class VoiceWebhookTest extends TestCase
{
    use RefreshDatabase;

    // ─── Setup ───────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure we are in testing environment so signature bypass applies
        $this->assertEquals('testing', app()->environment());

        // Set feature flag on globally for most tests
        FeatureFlag::set('voice.inbound', 'on');
        FeatureFlag::set('voice.tools', 'on');
    }

    protected function tearDown(): void
    {
        FeatureFlag::forget('voice.inbound');
        FeatureFlag::forget('voice.tools');
        parent::tearDown();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function twilioPayload(array $overrides = []): array
    {
        return array_merge([
            'CallSid' => 'CA'.str_repeat('0', 32),
            'From' => '+15555551234',
            'To' => '+15555557890',
            'CallStatus' => 'ringing',
        ], $overrides);
    }

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Test Tenant',
            'slug' => 'voice-'.Str::lower(Str::random(10)),
            'status' => 'active',
            'plan' => 'starter',
        ]);
    }

    private function createVoiceNumber(Tenant $tenant, string $phone = '+15555557890'): VoiceNumber
    {
        return VoiceNumber::create([
            'tenant_id' => $tenant->id,
            'phone_number' => $phone,
            'provider' => 'twilio',
            'agent_id' => 'voice_receptionist',
            'config' => ['greeting' => 'Hello from test!'],
            'is_active' => true,
        ]);
    }

    // ─── Signature verification ───────────────────────────────────────────────

    /**
     * In 'testing' env the signature middleware is bypassed — so we verify
     * that the bypass works (request reaches controller).
     */
    public function test_inbound_bypasses_signature_check_in_testing_env(): void
    {
        // No X-Twilio-Signature header, no registered number → expect fallback TwiML not a 403
        $response = $this->post('/api/voice/inbound', $this->twilioPayload());

        // Should not be a 403 auth rejection
        $this->assertNotEquals(403, $response->status());
        $response->assertHeader('Content-Type', 'application/xml');
    }

    // ─── Feature flag kill-switch ─────────────────────────────────────────────

    public function test_inbound_blocked_when_feature_flag_off(): void
    {
        FeatureFlag::set('voice.inbound', 'off');

        $response = $this->post('/api/voice/inbound', $this->twilioPayload());

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        // Should contain a Say or Hangup (graceful unavailable)
        $this->assertTrue(
            isset($xml->Say) || isset($xml->Hangup),
            'Expected a Say or Hangup element in unavailable TwiML'
        );
    }

    // ─── Tenant resolution ───────────────────────────────────────────────────

    public function test_inbound_returns_fallback_twiml_when_number_not_registered(): void
    {
        $response = $this->post('/api/voice/inbound', $this->twilioPayload([
            'To' => '+19999999999', // no matching voice_number
        ]));

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $body = $response->getContent();
        $this->assertStringContainsString('not currently configured', $body);
    }

    public function test_inbound_responds_with_greeting_twiml_when_number_registered(): void
    {
        $tenant = $this->createTenant();
        $this->createVoiceNumber($tenant);

        // Mock cost governor to allow
        $this->mockCostGovernorAllows();

        $response = $this->post('/api/voice/inbound', $this->twilioPayload());

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $body = $response->getContent();
        $xml = simplexml_load_string($body);
        $this->assertNotFalse($xml);

        // Expect Gather element (voice interactive loop)
        $this->assertTrue(isset($xml->Gather) || isset($xml->Say));
    }

    // ─── Gather endpoint ─────────────────────────────────────────────────────

    public function test_gather_returns_reprompt_when_no_speech(): void
    {
        $tenant = $this->createTenant();
        $this->createVoiceNumber($tenant);

        $response = $this->post('/api/voice/gather', [
            'CallSid' => 'CAtest001',
            'SpeechResult' => '',
            'From' => '+15555551234',
            'To' => '+15555557890',
        ]);

        $response->assertStatus(200);
        $body = $response->getContent();
        $this->assertStringContainsString("didn't catch", $body);
    }

    // ─── Status endpoint ─────────────────────────────────────────────────────

    public function test_status_webhook_updates_call_record(): void
    {
        $tenant = $this->createTenant();

        $call = VoiceCall::create([
            'tenant_id' => $tenant->id,
            'call_sid' => 'CAstatus001',
            'phone_number' => '+15555557890',
            'from_number' => '+15555551234',
            'direction' => 'inbound',
            'status' => 'in-progress',
            'started_at' => now(),
        ]);

        $response = $this->post('/api/voice/status', [
            'CallSid' => 'CAstatus001',
            'CallStatus' => 'completed',
            'CallDuration' => 45,
        ]);

        $response->assertStatus(200);
        $this->assertSame('OK', $response->getContent());

        $call->refresh();
        $this->assertSame('completed', $call->status);
        $this->assertSame(45, $call->duration_seconds);
        $this->assertNotNull($call->ended_at);
    }

    // ─── Recording endpoint ───────────────────────────────────────────────────

    public function test_recording_webhook_stores_recording_url(): void
    {
        $tenant = $this->createTenant();

        VoiceCall::create([
            'tenant_id' => $tenant->id,
            'call_sid' => 'CArecord001',
            'phone_number' => '+15555557890',
            'from_number' => '+15555551234',
            'direction' => 'inbound',
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $response = $this->post('/api/voice/recording', [
            'CallSid' => 'CArecord001',
            'RecordingUrl' => 'https://api.twilio.com/recordings/RE001',
            'RecordingSid' => 'RE001',
            'RecordingDuration' => 30,
        ]);

        $response->assertStatus(200);

        $call = VoiceCall::where('call_sid', 'CArecord001')->first();
        $this->assertNotNull($call->metadata);
        $this->assertSame('https://api.twilio.com/recordings/RE001', $call->metadata['recording_url']);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function mockCostGovernorAllows(): void
    {
        $mock = \Mockery::mock(CostGovernor::class);
        $mock->shouldReceive('canExecute')->andReturn([
            'allowed' => true,
            'degraded' => false,
            'action' => 'allow',
        ]);
        $this->app->instance(CostGovernor::class, $mock);
    }
}
