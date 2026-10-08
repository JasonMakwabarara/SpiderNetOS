<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Models\Tenant;
use App\Models\User;
use App\Models\VoicePersona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared fixture for the Atlas voice tests (plan D7 §7): a tenant with an
 * admin, voice.atlas_speak on, fake disks, and a faked inference plane whose
 * POST /tts answers are staged per test and whose requests are recorded.
 */
abstract class VoiceTestCase extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $admin;

    /** @var list<Request> */
    protected array $ttsRequests = [];

    /** @var array<string, mixed>|int status code, or the JSON body the plane returns */
    protected array|int $ttsReply = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('Northbeam Books');
        $this->admin = $this->makeUser($this->tenant, 'admin', 'Thandi');

        config()->set('voice.default_persona', null);
        config()->set('services.inference.url', 'http://inference.test');
        config()->set('services.inference.token', 'plane-token');
        $this->flags(['voice.atlas_speak' => 'on', 'voice.inbound' => 'on']);

        Storage::fake('local');
        Storage::fake('public');

        $this->ttsReply = $this->ttsBody('ID3-atlas-audio');
        Http::fake([
            '*/tts' => function (Request $request) {
                $this->ttsRequests[] = $request;

                return is_int($this->ttsReply)
                    ? Http::response(['detail' => 'no TTS persona configured'], $this->ttsReply)
                    : Http::response($this->ttsReply);
            },
        ]);
    }

    protected function makeTenant(string $name, array $settings = []): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(), 'name' => $name, 'slug' => 'voice-'.Str::lower(Str::random(8)),
            'status' => 'active', 'plan' => 'pro', 'automation_level' => 'assisted',
            'onboarding_completed_at' => now(), 'settings' => $settings,
        ]);
    }

    protected function makeUser(Tenant $tenant, string $role = 'member', string $name = 'Sipho'): User
    {
        return User::create([
            'tenant_id' => $tenant->id, 'name' => $name, 'email' => Str::lower(Str::random(10)).'@voice.test',
            'password' => bcrypt('secret-password'), 'role' => $role, 'onboarding_completed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function persona(string $slug, array $overrides = []): VoicePersona
    {
        return VoicePersona::create(array_merge([
            'slug' => $slug,
            'display_name' => Str::headline($slug),
            'provider' => 'elevenlabs',
            'provider_voice_id' => 'voice-'.$slug,
            'language' => 'en',
            'accent' => 'South African English',
            'gender' => 'female',
            'output_format' => 'mp3_44100_128',
            'cost_per_1k_chars' => 0.3,
            'tags' => ['elevenlabs'],
            'recommended_for' => ['atlas'],
            'consent' => ['type' => 'stock'],
            'is_active' => true,
        ], $overrides));
    }

    /** @param array<string, string> $values */
    protected function flags(array $values): void
    {
        config()->set('features', array_merge((array) config('features'), $values));
        Cache::flush();
    }

    /** @return array<string, mixed> */
    protected function ttsBody(string $audio, string $provider = 'elevenlabs', ?string $fallbackFrom = null, string $contentType = 'audio/mpeg'): array
    {
        return [
            'audio_base64' => base64_encode($audio), 'content_type' => $contentType, 'provider' => $provider,
            'duration_estimate' => 1.2, 'characters' => 20, 'voice' => 'v', 'persona' => null, 'fallback_from' => $fallbackFrom,
        ];
    }

    protected function api(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->admin, 'sanctum');
    }
}
