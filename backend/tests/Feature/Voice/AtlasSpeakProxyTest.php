<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Models\Event;
use App\Services\Voice\AtlasSpeechService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * POST /api/atlas/speak → the inference plane's POST /tts (faked): flag gate,
 * the request contract, audio passthrough, the 24 h per-tenant cache keyed
 * sha256(text|persona), usage recording, message_id lookup, the kill switch,
 * and upstream failures (plan D7 §7).
 */
class AtlasSpeakProxyTest extends VoiceTestCase
{
    public function test_flag_off_is_403_and_the_plane_is_never_called(): void
    {
        $this->persona('eleven-leah');
        $this->flags(['voice.atlas_speak' => 'off']);

        $this->api()->postJson('/api/atlas/speak', ['text' => 'Good morning.'])
            ->assertForbidden()
            ->assertJsonPath('reason', 'voice.atlas_speak_off');
        $this->api()->postJson('/api/atlas/speak', [])->assertForbidden(); // the flag gates before validation

        $this->assertSame([], $this->ttsRequests);
        $this->getJson('/api/atlas/speak')->assertStatus(405);
    }

    public function test_speaks_through_the_plane_caches_for_24_hours_and_records_usage(): void
    {
        $this->persona('eleven-leah', ['cost_per_1k_chars' => 0.3, 'style' => 'calm', 'rate' => '1.0']);
        $text = "Good morning.  I have read the week's numbers;\nthree things need you.";
        $normalized = "Good morning. I have read the week's numbers; three things need you.";

        $first = $this->api()->post('/api/atlas/speak', ['text' => $text], ['Accept' => 'audio/mpeg']);
        $first->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('X-Atlas-Voice-Persona', 'eleven-leah')
            ->assertHeader('X-Atlas-Voice-Provider', 'elevenlabs')
            ->assertHeader('X-Atlas-Voice-Cache', 'miss');
        $this->assertSame('ID3-atlas-audio', $first->getContent());

        $this->assertCount(1, $this->ttsRequests);
        $request = $this->ttsRequests[0];
        $this->assertSame('http://inference.test/tts', $request->url());
        $this->assertSame('Bearer plane-token', $request->header('Authorization')[0]);
        $this->assertSame([
            'text' => $normalized,
            'provider' => 'elevenlabs',
            'voice' => 'voice-eleven-leah',
            'persona' => [
                'slug' => 'eleven-leah', 'provider' => 'elevenlabs', 'voice_id' => 'voice-eleven-leah', 'provider_voice_id' => 'voice-eleven-leah',
                'language' => 'en', 'accent' => 'South African English', 'gender' => 'female', 'style' => 'calm', 'rate' => 1.0,
                'output_format' => 'mp3_44100_128',
            ],
            'format' => 'mp3',
        ], $request->data());

        // Cached on the local disk under the tenant, keyed sha256(text|persona).
        $key = hash('sha256', $normalized.'|eleven-leah');
        $this->assertSame($key, AtlasSpeechService::cacheKey($normalized, 'eleven-leah'));
        Storage::disk('local')->assertExists("voice/atlas-speak/{$this->tenant->id}/{$key}.mp3");

        $this->api()->post('/api/atlas/speak', ['text' => $normalized])
            ->assertOk()->assertHeader('X-Atlas-Voice-Cache', 'hit')->assertContent('ID3-atlas-audio');
        $this->assertCount(1, $this->ttsRequests);

        // Another tenant never shares the clip.
        $other = $this->makeUser($this->makeTenant('Other Co'), 'admin');
        $this->api($other)->post('/api/atlas/speak', ['text' => $normalized])->assertOk()->assertHeader('X-Atlas-Voice-Cache', 'miss');
        $this->assertCount(2, $this->ttsRequests);

        // After 24 h the clip is rendered again.
        $this->travel(25)->hours();
        $this->api()->post('/api/atlas/speak', ['text' => $normalized])->assertOk()->assertHeader('X-Atlas-Voice-Cache', 'miss');
        $this->assertCount(3, $this->ttsRequests);

        // Fresh renders record `tts` usage (cache hits do not).
        $usage = Event::query()->where('tenant_id', $this->tenant->id)->where('event_type', 'usage.recorded')->get();
        $this->assertCount(2, $usage);
        $payload = $usage->first()->payload;
        $this->assertSame('tts', $payload['resource_type']);
        $this->assertEqualsWithDelta(mb_strlen($normalized) / 1000 * 0.3, $payload['cost_usd'], 0.000001);
        $this->assertSame(['surface' => 'atlas_speak', 'persona' => 'eleven-leah', 'provider' => 'elevenlabs', 'fallback_from' => null,
            'characters' => mb_strlen($normalized), 'user_id' => (string) $this->admin->id], $payload['metadata']);
    }

    public function test_validation_message_id_lookup_and_persona_override(): void
    {
        $this->persona('eleven-leah');
        $this->persona('fish-kenyan', ['provider' => 'fishaudio', 'provider_voice_id' => '97896edf24ee424f86eef9b97457c349']);

        $this->api()->postJson('/api/atlas/speak', ['text' => str_repeat('a', 2001)])->assertStatus(422)->assertJsonValidationErrors('text');
        $this->api()->postJson('/api/atlas/speak', [])->assertStatus(422)->assertJsonValidationErrors(['text', 'message_id']);
        $this->api()->postJson('/api/atlas/speak', ['text' => '   '])->assertStatus(422);
        $this->api()->postJson('/api/atlas/speak', ['text' => 'Hi.', 'persona_slug' => 'nobody'])->assertStatus(422)->assertJsonPath('reason', 'unknown_persona');
        $this->assertSame([], $this->ttsRequests);

        // A non-uuid message id never reaches the uuid column.
        $bindings = [];
        DB::listen(function ($query) use (&$bindings): void {
            $bindings = array_merge($bindings, $query->bindings);
        });
        $this->api()->postJson('/api/atlas/speak', ['message_id' => 'msg-123'])->assertNotFound();
        $this->assertNotContains('msg-123', $bindings);
        $this->api()->postJson('/api/atlas/speak', ['message_id' => (string) Str::uuid()])->assertNotFound();

        // message_id = the interaction_id of one of the user's Atlas replies: its visible contract is spoken.
        $interactionId = (string) Str::uuid();
        DB::table('atlas_interactions')->insert([
            'id' => $interactionId, 'tenant_id' => $this->tenant->id, 'user_id' => $this->admin->id, 'user_input' => 'How are sales?',
            'parsed_intent' => '{}', 'execution_result' => '{}', 'generation' => '{}',
            'atlas_response' => json_encode(['future_state' => 'Two deals close this week', 'value' => 'Cash lands before payroll.',
                'emotional_shift' => 'Less worry', 'action_summary' => 'I drafted both follow-ups', 'details' => 'internal ids 42']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->api()->post('/api/atlas/speak', ['message_id' => $interactionId, 'persona_slug' => 'fish-kenyan'])
            ->assertOk()->assertHeader('X-Atlas-Voice-Persona', 'fish-kenyan');
        $sent = end($this->ttsRequests)->data();
        $this->assertSame('Two deals close this week. Cash lands before payroll. Less worry. I drafted both follow-ups.', $sent['text']);
        $this->assertSame(['fishaudio', '97896edf24ee424f86eef9b97457c349', 'fish-kenyan'], [$sent['provider'], $sent['voice'], $sent['persona']['slug']]);

        // Someone else's reply in the same tenant is not theirs to play.
        $colleague = $this->makeUser($this->tenant, 'member');
        $this->api($colleague)->postJson('/api/atlas/speak', ['message_id' => $interactionId])->assertNotFound();
    }

    public function test_kill_switch_no_persona_upstream_failures_and_fallback_audio_is_not_cached(): void
    {
        // No persona at all → 503.
        $this->api()->postJson('/api/atlas/speak', ['text' => 'Hello.'])->assertStatus(503)->assertJsonPath('reason', 'no_persona');

        $this->persona('eleven-leah');

        // The voice vertical's kill switch (VoiceSafetyGuard) silences Atlas too.
        $this->flags(['voice.inbound' => 'off']);
        $this->api()->postJson('/api/atlas/speak', ['text' => 'Hello.'])->assertForbidden()->assertJsonPath('reason', 'voice_killed');
        $this->flags(['voice.inbound' => 'on']);
        $this->assertSame([], $this->ttsRequests);

        // The plane has no persona configured / is failing.
        $this->ttsReply = 503;
        $this->api()->postJson('/api/atlas/speak', ['text' => 'Hello.'])->assertStatus(503)->assertJsonPath('reason', 'tts_unavailable');
        $this->ttsReply = 500;
        $this->api()->postJson('/api/atlas/speak', ['text' => 'Hello.'])->assertStatus(502)->assertJsonPath('reason', 'tts_unavailable');
        $this->ttsReply = ['audio_base64' => '', 'content_type' => 'audio/mpeg', 'provider' => 'elevenlabs'];
        $this->api()->postJson('/api/atlas/speak', ['text' => 'Hello.'])->assertStatus(502);

        // Piper spoke instead of the persona's provider: served, flagged, never cached.
        $this->ttsReply = $this->ttsBody('RIFF-piper', 'piper', 'elevenlabs', 'audio/wav');
        foreach ([1, 2] as $attempt) {
            $this->api()->post('/api/atlas/speak', ['text' => 'Hello.'])
                ->assertOk()
                ->assertHeader('Content-Type', 'audio/wav')
                ->assertHeader('X-Atlas-Voice-Fallback-From', 'elevenlabs')
                ->assertHeader('X-Atlas-Voice-Cache', 'miss');
        }
        $this->assertCount(5, $this->ttsRequests);
        $this->assertSame([], Storage::disk('local')->allFiles('voice/atlas-speak'));
        Http::assertSentCount(5);
    }
}
