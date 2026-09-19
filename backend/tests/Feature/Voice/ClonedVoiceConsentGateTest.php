<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Models\VoicePersona;
use App\Services\Voice\VoicePersonaCatalogue;
use Illuminate\Support\Facades\Storage;

/**
 * Only consenting people may be cloned (plan D7 §7): a cloned voice without
 * complete consent — subject_name, consent_doc_url and a real, past
 * consented_at — is refused by the catalogue everywhere: the picker, the
 * user's and the tenant's choice, resolution, previews and speech.
 */
class ClonedVoiceConsentGateTest extends VoiceTestCase
{
    public function test_cloned_voice_without_complete_consent_is_refused_everywhere(): void
    {
        $this->persona('eleven-leah');
        $incomplete = [
            'missing-doc' => ['type' => 'cloned', 'subject_name' => 'Jason', 'consented_at' => '2026-09-01T10:00:00Z'],
            'missing-name' => ['type' => 'cloned', 'consent_doc_url' => 'https://docs.example/consent.pdf', 'consented_at' => '2026-09-01'],
            'bad-date' => ['type' => 'cloned', 'subject_name' => 'Jason', 'consent_doc_url' => 'https://docs.example/c.pdf', 'consented_at' => 'yesterday-ish'],
            'future-date' => ['type' => 'cloned', 'subject_name' => 'Jason', 'consent_doc_url' => 'https://docs.example/c.pdf', 'consented_at' => now()->addYear()->toIso8601String()],
        ];
        foreach ($incomplete as $suffix => $consent) {
            $this->persona('cloned-'.$suffix, ['consent' => $consent, 'is_default' => $suffix === 'missing-doc']);
        }
        Storage::disk('public')->put('voice-previews/cloned-missing-doc/greeting.mp3', 'ID3-jason');
        $catalogue = app(VoicePersonaCatalogue::class);

        foreach (array_keys($incomplete) as $suffix) {
            $persona = VoicePersona::where('slug', 'cloned-'.$suffix)->firstOrFail();
            $this->assertFalse($persona->hasCompleteConsent(), $suffix);
            $this->assertSame('consent_incomplete', $catalogue->refusal($persona), $suffix);
        }

        // Not offered, not selectable by the user or the tenant admin.
        $this->assertSame(['eleven-leah'], array_column($this->api()->getJson('/api/voice/personas')->json('data'), 'slug'));
        $this->api()->putJson('/api/me/voice', ['persona_slug' => 'cloned-missing-doc'])
            ->assertStatus(422)->assertJsonPath('reason', 'consent_incomplete');
        $this->api()->putJson('/api/admin/tenant/voice-default', ['persona_slug' => 'cloned-missing-doc'])
            ->assertStatus(422)->assertJsonPath('reason', 'consent_incomplete');

        // Chosen before consent lapsed (written directly): resolution skips it, even as the catalogue default.
        $this->admin->forceFill(['voice_persona_slug' => 'cloned-missing-doc'])->save();
        $this->tenant->forceFill(['settings' => ['voice' => ['default_persona' => 'cloned-bad-date']]])->save();
        $resolved = $catalogue->resolve($this->admin->fresh());
        $this->assertSame('eleven-leah', $resolved['persona']?->slug);
        $this->assertSame(['consent_incomplete', 'consent_incomplete'], array_column($resolved['skipped'], 'reason'));

        // Never spoken, never previewed.
        $this->api()->postJson('/api/atlas/speak', ['text' => 'Hello.', 'persona_slug' => 'cloned-future-date'])
            ->assertStatus(422)->assertJsonPath('reason', 'consent_incomplete');
        $this->api()->getJson('/api/voice/personas/cloned-missing-doc/preview')->assertNotFound();
        $this->api()->post('/api/atlas/speak', ['text' => 'Hello.'])->assertOk()->assertHeader('X-Atlas-Voice-Persona', 'eleven-leah');
        $this->assertCount(1, $this->ttsRequests);
        $this->assertSame('eleven-leah', $this->ttsRequests[0]->data()['persona']['slug']);
    }

    public function test_cloned_voice_with_complete_consent_speaks_and_is_labelled(): void
    {
        $this->persona('cloned-thandi', [
            'display_name' => 'Thandi (cloned)',
            'consent' => [
                'type' => 'cloned', 'subject_name' => 'Thandi Nkosi',
                'consent_doc_url' => 'https://docs.example/thandi-consent.pdf', 'consented_at' => '2026-09-10T09:00:00Z',
            ],
        ]);

        $listed = $this->api()->getJson('/api/voice/personas')->assertOk()->json('data');
        $this->assertSame(['cloned-thandi'], array_column($listed, 'slug'));
        $this->assertSame(['type' => 'cloned', 'label' => 'AI voice of Thandi Nkosi'], $listed[0]['consent']);
        $this->assertStringNotContainsString('thandi-consent.pdf', json_encode($listed));

        $this->api()->putJson('/api/me/voice', ['persona_slug' => 'cloned-thandi'])->assertOk();
        $this->api()->post('/api/atlas/speak', ['text' => 'Hello.'])->assertOk()->assertHeader('X-Atlas-Voice-Persona', 'cloned-thandi');

        // Consent withdrawn → refused again from the next request on.
        VoicePersona::where('slug', 'cloned-thandi')->firstOrFail()
            ->forceFill(['consent' => ['type' => 'cloned', 'subject_name' => 'Thandi Nkosi']])->save();
        $this->api()->postJson('/api/atlas/speak', ['text' => 'Hello again.'])->assertStatus(503)->assertJsonPath('reason', 'no_persona');
        $this->assertCount(1, $this->ttsRequests);
    }
}
