<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Models\VoicePersona;
use App\Services\Voice\VoicePersonaCatalogue;

/**
 * Which voice Atlas speaks with (plan D7 §7): users.voice_persona_slug →
 * tenants.settings.voice.default_persona → config('voice.default_persona') →
 * the first active persona (the default first), skipping any persona that
 * cannot speak at each step.
 */
class VoiceDefaultResolutionTest extends VoiceTestCase
{
    public function test_resolution_order_user_then_tenant_then_config_then_first_active(): void
    {
        $catalogue = app(VoicePersonaCatalogue::class);
        $this->persona('a-eleven-first');
        $this->persona('b-catalogue-default', ['provider' => 'fishaudio', 'is_default' => true]);
        $this->persona('c-config', ['provider' => 'intron', 'provider_voice_id' => 'en/zulu/male']);
        $this->persona('d-tenant', ['provider' => 'fishaudio']);
        $this->persona('e-user', ['provider' => 'piper', 'provider_voice_id' => null]);

        $user = $this->admin;
        $user->forceFill(['voice_persona_slug' => 'e-user'])->save();
        $this->tenant->forceFill(['settings' => ['voice' => ['default_persona' => 'd-tenant']]])->save();
        config()->set('voice.default_persona', 'c-config');

        $this->assertResolved($catalogue, 'e-user', 'user');

        $user->forceFill(['voice_persona_slug' => null])->save();
        $this->assertResolved($catalogue, 'd-tenant', 'tenant');

        $this->tenant->forceFill(['settings' => ['voice' => []]])->save();
        $this->assertResolved($catalogue, 'c-config', 'config');

        // Nothing chosen: the catalogue default beats provider order.
        config()->set('voice.default_persona', null);
        $this->assertResolved($catalogue, 'b-catalogue-default', 'catalogue');

        // Unusable choices are skipped, and reported, at every step.
        $user->forceFill(['voice_persona_slug' => 'gone'])->save();
        $this->persona('retired-tenant-choice', ['is_active' => false]);
        $this->tenant->forceFill(['settings' => ['voice' => ['default_persona' => 'retired-tenant-choice']]])->save();
        config()->set('voice.default_persona', 'c-config');
        $resolved = $catalogue->resolve($user->fresh());
        $this->assertSame(['c-config', 'config'], [$resolved['persona']?->slug, $resolved['source']]);
        $this->assertSame([
            ['source' => 'user', 'slug' => 'gone', 'reason' => 'unknown_persona'],
            ['source' => 'tenant', 'slug' => 'retired-tenant-choice', 'reason' => 'inactive'],
        ], $resolved['skipped']);

        // No persona at all.
        VoicePersona::query()->update(['is_active' => false]);
        $this->assertNull($catalogue->resolveForUser($user->fresh()));
        $this->assertNull($catalogue->resolve($user->fresh())['source']);
    }

    public function test_speak_and_me_voice_use_the_resolved_persona_per_user(): void
    {
        $this->persona('eleven-leah');
        $this->persona('fish-tenant', ['provider' => 'fishaudio']);
        $this->persona('intron-sipho', ['provider' => 'intron', 'provider_voice_id' => 'en/xhosa/male', 'output_format' => 'wav']);
        $this->tenant->forceFill(['settings' => ['voice' => ['default_persona' => 'fish-tenant']]])->save();
        $sipho = $this->makeUser($this->tenant, 'member', 'Sipho');
        $sipho->forceFill(['voice_persona_slug' => 'intron-sipho'])->save();

        $this->api()->post('/api/atlas/speak', ['text' => 'Morning.'])->assertOk()->assertHeader('X-Atlas-Voice-Persona', 'fish-tenant');
        $this->api($sipho)->post('/api/atlas/speak', ['text' => 'Morning.'])->assertOk()->assertHeader('X-Atlas-Voice-Persona', 'intron-sipho');

        $this->assertSame(['fishaudio', 'intron'], array_map(fn ($request) => $request->data()['persona']['provider'], $this->ttsRequests));
        $this->assertSame('en/xhosa/male', $this->ttsRequests[1]->data()['voice']);
        $this->assertSame('wav', $this->ttsRequests[1]->data()['persona']['output_format']);

        $this->api($sipho)->getJson('/api/me/voice')
            ->assertJsonPath('data.resolved_persona.slug', 'intron-sipho')
            ->assertJsonPath('data.resolved_from', 'user');
        $this->api()->getJson('/api/voice/personas')
            ->assertJsonPath('meta.resolved_persona', 'fish-tenant')
            ->assertJsonPath('meta.tenant_default', 'fish-tenant');
    }

    private function assertResolved(VoicePersonaCatalogue $catalogue, string $slug, string $source): void
    {
        $user = $this->admin->fresh();
        $resolved = $catalogue->resolve($user);
        $this->assertSame([$slug, $source], [$resolved['persona']?->slug, $resolved['source']]);
        $this->assertSame($slug, $catalogue->resolveForUser($user)?->slug);
    }
}
