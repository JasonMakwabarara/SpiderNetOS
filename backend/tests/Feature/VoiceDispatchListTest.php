<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessVoiceCallSummary;
use App\Models\Tenant;
use App\Models\VoiceCall;
use App\Services\TelephonyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The two voice producers of agent:dispatch used to Redis::publish only.
 * intelligence/main.py consumes that key with BLPOP (a list), so nothing
 * ever reached it. Both must now LPUSH the same envelope MetaPlanner pushes
 * (top-level tenant_id + agent_id), keeping publish for observers.
 *
 * Laravel has no Redis::fake(); the facade root is mocked with shouldReceive()
 * and the pushed payload captured, as ConversationBridgeTest does.
 */
class VoiceDispatchListTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Voice Co',
            'slug' => 'voice-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'starter',
        ]);
    }

    private function createCall(Tenant $tenant, string $callSid): VoiceCall
    {
        return VoiceCall::create([
            'tenant_id' => $tenant->id,
            'call_sid' => $callSid,
            'phone_number' => '+15555557890',
            'from_number' => '+15555551234',
            'direction' => 'inbound',
            'status' => 'in-progress',
        ]);
    }

    public function test_completed_call_status_lpushes_post_call_dispatch_with_top_level_tenant_and_agent(): void
    {
        Bus::fake([ProcessVoiceCallSummary::class]);

        $tenant = $this->createTenant();
        $callSid = 'CA'.str_repeat('1', 32);
        $this->createCall($tenant, $callSid);

        $pushedKey = null;
        $pushedPayload = null;
        Redis::shouldReceive('lpush')
            ->once()
            ->andReturnUsing(function ($key, $payload) use (&$pushedKey, &$pushedPayload) {
                $pushedKey = $key;
                $pushedPayload = $payload;

                return 1;
            });

        $published = [];
        Redis::shouldReceive('publish')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($channel, $payload) use (&$published) {
                $published[$channel][] = $payload;

                return 1;
            });

        $response = $this->post('/api/voice/status', [
            'CallSid' => $callSid,
            'CallStatus' => 'completed',
            'CallDuration' => 42,
        ]);

        $response->assertStatus(200);

        $this->assertSame('agent:dispatch', $pushedKey);
        $envelope = json_decode((string) $pushedPayload, true);
        $this->assertIsArray($envelope);

        // The BLPOP consumer reads these two from the top level of the envelope.
        $this->assertSame((string) $tenant->id, $envelope['tenant_id']);
        $this->assertSame('nexus', $envelope['agent_id']);
        $this->assertSame('voice.post_call_process', $envelope['intent']);
        $this->assertSame($callSid, $envelope['context']['call_sid']);
        $this->assertSame((string) $tenant->id, $envelope['context']['tenant_id']);

        // Publish is kept for passive observers and carries the identical envelope.
        $this->assertArrayHasKey('agent:dispatch', $published);
        $this->assertSame($pushedPayload, $published['agent:dispatch'][0]);

        // The Phase C structured-summary job still goes out.
        Bus::assertDispatched(ProcessVoiceCallSummary::class);

        $this->assertSame('completed', VoiceCall::where('call_sid', $callSid)->value('status'));
    }

    public function test_completed_status_for_unknown_call_does_not_push_a_tenantless_envelope(): void
    {
        Bus::fake([ProcessVoiceCallSummary::class]);

        Redis::shouldReceive('lpush')->never();
        Redis::shouldReceive('publish')->zeroOrMoreTimes()->andReturn(1);

        $response = $this->post('/api/voice/status', [
            'CallSid' => 'CA'.str_repeat('9', 32),
            'CallStatus' => 'completed',
        ]);

        $response->assertStatus(200);
    }

    public function test_telephony_dispatch_voice_intent_lpushes_and_publishes_the_same_envelope(): void
    {
        $tenant = $this->createTenant();

        $pushedKey = null;
        $pushedPayload = null;
        Redis::shouldReceive('lpush')
            ->once()
            ->andReturnUsing(function ($key, $payload) use (&$pushedKey, &$pushedPayload) {
                $pushedKey = $key;
                $pushedPayload = $payload;

                return 1;
            });

        $publishedChannel = null;
        $publishedPayload = null;
        Redis::shouldReceive('publish')
            ->once()
            ->andReturnUsing(function ($channel, $payload) use (&$publishedChannel, &$publishedPayload) {
                $publishedChannel = $channel;
                $publishedPayload = $payload;

                return 1;
            });

        app(TelephonyService::class)->dispatchVoiceIntent(
            (string) $tenant->id,
            'voice_receptionist',
            'CA'.str_repeat('2', 32),
            'I would like to book a call',
            ['language' => 'en'],
        );

        $this->assertSame('agent:dispatch', $pushedKey);
        $this->assertSame('agent:dispatch', $publishedChannel);
        $this->assertSame($pushedPayload, $publishedPayload);

        $envelope = json_decode((string) $pushedPayload, true);
        $this->assertSame((string) $tenant->id, $envelope['tenant_id']);
        $this->assertSame('voice_receptionist', $envelope['agent_id']);
        $this->assertSame('voice.interaction', $envelope['intent']);
        $this->assertSame('I would like to book a call', $envelope['context']['caller_input']);
        $this->assertSame('voice', $envelope['context']['channel']);
        $this->assertSame('voice_chunk', $envelope['context']['response_format']);
        $this->assertSame('en', $envelope['context']['language']);
    }
}
