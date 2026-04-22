<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\VoiceCall;
use App\Services\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VoiceStreamingTest — Phase C
 *
 * Tests for the streaming connection handshake:
 * - Feature flag off → Gather fallback TwiML
 * - Feature flag on → <Connect><Stream> TwiML with correct WS URL
 * - Unknown call_sid → graceful response
 */
class VoiceStreamingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FeatureFlag::set('voice.inbound', 'on');
    }

    protected function tearDown(): void
    {
        FeatureFlag::forget('voice.inbound');
        FeatureFlag::forget('voice.streaming');
        parent::tearDown();
    }

    private function streamPayload(): array
    {
        return [
            'CallSid' => 'CAstream001',
            'From'    => '+15555551234',
            'To'      => '+15555557890',
        ];
    }

    public function test_connect_returns_gather_fallback_when_streaming_flag_off(): void
    {
        FeatureFlag::set('voice.streaming', 'off');

        $response = $this->post('/api/voice/stream/connect', $this->streamPayload());

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $body = $response->getContent();

        // Fallback should have a Gather element, not a Connect/Stream
        $this->assertStringNotContainsString('<Connect>', $body);
        $this->assertStringContainsString('<Gather', $body);
    }

    public function test_connect_returns_stream_twiml_when_flag_on(): void
    {
        FeatureFlag::set('voice.streaming', 'on');

        $tenant = \App\Models\Tenant::create([
            'id'     => \Illuminate\Support\Str::uuid(),
            'name'   => 'Stream Tenant',
            'status' => 'active',
            'plan'   => 'starter',
        ]);

        \App\Models\VoiceNumber::create([
            'tenant_id'    => $tenant->id,
            'phone_number' => '+15555557890',
            'provider'     => 'twilio',
            'agent_id'     => 'voice_receptionist',
            'is_active'    => true,
        ]);

        $response = $this->post('/api/voice/stream/connect', $this->streamPayload());

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');

        $body = $response->getContent();
        $xml  = simplexml_load_string($body);

        $this->assertNotFalse($xml);

        // Must contain <Connect><Stream> with a url attribute
        $this->assertTrue(
            isset($xml->Connect->Stream),
            '<Connect><Stream> not found in TwiML when streaming=on'
        );
    }

    public function test_stream_session_id_is_recorded_on_voice_call(): void
    {
        FeatureFlag::set('voice.streaming', 'on');

        $tenant = \App\Models\Tenant::create([
            'id'     => \Illuminate\Support\Str::uuid(),
            'name'   => 'Stream T2',
            'status' => 'active',
            'plan'   => 'starter',
        ]);

        \App\Models\VoiceNumber::create([
            'tenant_id'    => $tenant->id,
            'phone_number' => '+15555557890',
            'provider'     => 'twilio',
            'agent_id'     => 'voice_receptionist',
            'is_active'    => true,
        ]);

        // Create a matching call record
        VoiceCall::create([
            'tenant_id'    => $tenant->id,
            'call_sid'     => 'CAstream001',
            'phone_number' => '+15555557890',
            'from_number'  => '+15555551234',
            'direction'    => 'inbound',
            'status'       => 'in-progress',
            'started_at'   => now(),
        ]);

        $this->post('/api/voice/stream/connect', $this->streamPayload());

        $call = VoiceCall::where('call_sid', 'CAstream001')->first();
        $this->assertNotNull($call);
        $this->assertNotNull($call->stream_session_id);
    }
}
