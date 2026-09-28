<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Models\VoicePersona;
use Database\Seeders\VoicePersonaSeeder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

/**
 * voice:sync-personas + GET /api/voice/personas(/{slug}/preview) +
 * GET|PUT /api/me/voice + PUT /api/admin/tenant/voice-default (plan D7 §7).
 */
class VoicePersonaApiTest extends VoiceTestCase
{
    public function test_sync_personas_seeds_the_shipped_catalogue_idempotently_and_keeps_azure_out(): void
    {
        $catalogue = Yaml::parseFile(VoicePersonaSeeder::cataloguePath());
        $expected = array_values(array_filter($catalogue['personas'], fn (array $p): bool => $p['provider'] !== 'azure'));
        $expectedActive = array_filter($expected, fn (array $p): bool => $p['provider'] === 'piper' || ! empty($p['provider_voice_id']));

        $this->artisan('voice:sync-personas')->assertExitCode(0);

        $this->assertSame(count($expected), VoicePersona::count());
        $this->assertSame(count($expectedActive), VoicePersona::where('is_active', true)->count());
        $this->assertSame(0, VoicePersona::where('provider', 'azure')->count());

        $design = VoicePersona::where('slug', 'elevenlabs-design-zimbabwean-man')->firstOrFail();
        $this->assertFalse($design->is_active); // a Voice Design candidate has no voice id until promoted
        $this->assertSame('design', $design->kind);
        $this->assertStringContainsString('Zimbabwean man', $design->meta['voice_description']);
        $zulu = VoicePersona::where('slug', 'intron-en-zulu-male')->firstOrFail();
        $this->assertTrue($zulu->is_active);
        // assertEquals, not assertSame: this round-trips through a jsonb column and
        // Postgres does not preserve object key order, so identity would compare the
        // storage engine's ordering rather than the value.
        $this->assertEquals(['voice_language' => 'en', 'voice_accent' => 'zulu', 'voice_gender' => 'male'], $zulu->meta['intron']);
        $this->assertSame(['character:sentinel'], $zulu->recommended_for);
        $this->assertSame('stock', $zulu->consentType());

        // Idempotent upsert by slug.
        $again = (new VoicePersonaSeeder)->sync();
        $this->assertSame([0, count($expected), 0], [$again['created'], $again['updated'], $again['deactivated']]);
        $this->assertSame(count($expected), VoicePersona::count());

        // A catalogue with an Azure voice and a default: Azure only with --include-azure;
        // personas that left the file are deactivated, never deleted.
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'voice-personas-'.bin2hex(random_bytes(6)).'.yaml';
        file_put_contents($path, Yaml::dump([
            'version' => 2,
            'default_persona' => 'fixture-eleven',
            'providers' => ['azure' => ['disabled_by_default' => true]],
            'personas' => [
                ['slug' => 'fixture-eleven', 'display_name' => 'Eleven', 'provider' => 'elevenlabs', 'provider_voice_id' => 'abc', 'consent' => ['type' => 'stock']],
                ['slug' => 'fixture-azure-luke', 'display_name' => 'Luke', 'provider' => 'azure', 'provider_voice_id' => 'en-ZA-LukeNeural',
                    'language' => 'en-ZA', 'style' => 'calm', 'style_degree' => 1.2, 'rate' => '+5%'],
            ],
        ], 4));

        try {
            $summary = (new VoicePersonaSeeder)->sync($path);
            $this->assertSame([1, 1, count($expectedActive), 'fixture-eleven'], [$summary['created'], $summary['skipped'], $summary['deactivated'], $summary['default']]);
            $this->assertNull(VoicePersona::where('slug', 'fixture-azure-luke')->first());
            $this->assertSame(['fixture-eleven'], VoicePersona::where('is_active', true)->pluck('slug')->all());
            $this->assertTrue(VoicePersona::where('slug', 'fixture-eleven')->value('is_default'));
            $this->assertSame(count($expected) + 1, VoicePersona::count());

            $this->artisan('voice:sync-personas', ['--path' => $path, '--include-azure' => true])->assertExitCode(0);
            $azure = VoicePersona::where('slug', 'fixture-azure-luke')->firstOrFail();
            $this->assertTrue($azure->disabled_by_default);
            $this->assertSame(['+5%', 1.2, 'calm'], [$azure->rate, $azure->style_degree, $azure->style]);

            $this->artisan('voice:sync-personas', ['--path' => $path.'.missing'])->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_personas_index_lists_usable_personas_default_first_without_voice_ids(): void
    {
        $this->persona('zz-piper', ['provider' => 'piper', 'provider_voice_id' => 'en_US-lessac-medium', 'cost_per_1k_chars' => 0.0]);
        $this->persona('fish-kenyan', ['provider' => 'fishaudio']);
        $this->persona('eleven-default', ['is_default' => true, 'provider' => 'intron', 'provider_voice_id' => 'en/zulu/male']);
        $this->persona('eleven-leah');
        $this->persona('eleven-retired', ['is_active' => false]);
        $this->persona('eleven-placeholder', ['provider_voice_id' => null]);
        $this->persona('eleven-cloned-no-consent', ['consent' => ['type' => 'cloned', 'subject_name' => 'Jason']]);

        $response = $this->api()->getJson('/api/voice/personas')->assertOk();

        $this->assertSame(['eleven-default', 'eleven-leah', 'fish-kenyan', 'zz-piper'], array_column($response->json('data'), 'slug'));
        $first = $response->json('data.0');
        $this->assertSame(['slug', 'display_name', 'provider', 'language', 'accent', 'gender', 'style', 'tags', 'recommended_for',
            'cost_per_1k_chars', 'consent', 'is_default', 'preview_url', 'preview_endpoint'], array_keys($first));
        $this->assertStringNotContainsString('voice-eleven-leah', $response->getContent());
        $this->assertSame('/api/voice/personas/eleven-default/preview', $first['preview_endpoint']);
        $this->assertSame(['type' => 'stock', 'label' => null], $first['consent']);
        $this->assertSame(['resolved_persona' => 'eleven-default', 'resolved_from' => 'catalogue', 'user_persona' => null,
            'tenant_default' => null, 'speak_available' => true, 'max_chars' => 2000, 'preview_lines' => ['greeting', 'verdict', 'apology']],
            $response->json('meta'));

    }

    /**
     * The guard check needs its own test: actingAs() authenticates for the rest
     * of the test, so asserting 401 after an authenticated call in the same
     * method would always have read back the still-authenticated session.
     */
    public function test_the_voice_routes_require_authentication(): void
    {
        $this->persona('eleven-default', ['is_default' => true]);

        $this->getJson('/api/voice/personas')->assertUnauthorized();
        $this->getJson('/api/voice/personas/eleven-default/preview')->assertUnauthorized();
        $this->getJson('/api/me/voice')->assertUnauthorized();
        $this->putJson('/api/me/voice', ['persona_slug' => 'eleven-default'])->assertUnauthorized();
        $this->postJson('/api/atlas/speak', ['text' => 'hello'])->assertUnauthorized();
    }

    public function test_preview_serves_the_rendered_sample_then_the_library_preview_then_404(): void
    {
        $this->persona('eleven-leah', ['preview_url' => 'https://storage.elevenlabs.example/leah.mp3']);
        $this->persona('piper-dev', ['provider' => 'piper']);
        Storage::disk('public')->put('voice-previews/eleven-leah/greeting.mp3', 'ID3-greeting');
        Storage::disk('public')->put('voice-previews/piper-dev/apology.wav', 'RIFF-apology');

        $this->api()->getJson('/api/voice/personas/eleven-leah/preview')
            ->assertOk()->assertHeader('Content-Type', 'audio/mpeg')->assertContent('ID3-greeting');
        $this->api()->get('/api/voice/personas/piper-dev/preview?line=apology')
            ->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $this->api()->get('/api/voice/personas/eleven-leah/preview?line=verdict')
            ->assertRedirect('https://storage.elevenlabs.example/leah.mp3');
        $this->api()->getJson('/api/voice/personas/piper-dev/preview?line=verdict')->assertNotFound();
        $this->api()->getJson('/api/voice/personas/eleven-leah/preview?line=sing')->assertStatus(422);
        $this->api()->getJson('/api/voice/personas/nobody/preview')->assertNotFound();
        $this->api()->getJson('/api/voice/personas/..%2F..%2Fenv/preview')->assertNotFound();
    }

    public function test_me_voice_round_trip_and_admin_gated_tenant_default(): void
    {
        $this->persona('eleven-leah');
        $this->persona('fish-kenyan', ['provider' => 'fishaudio']);
        $member = $this->makeUser($this->tenant, 'member');
        $member->forceFill(['preferences' => ['theme' => 'dark']])->save();

        $this->api($member)->getJson('/api/me/voice')->assertOk()->assertJson(['data' => [
            'persona_slug' => null, 'speak_enabled' => false, 'browser_fallback' => true, 'resolved_from' => 'catalogue', 'speak_available' => true,
        ]]);

        $this->api($member)->putJson('/api/me/voice', ['persona_slug' => 'fish-kenyan', 'speak_enabled' => true, 'browser_fallback' => false])
            ->assertOk()
            ->assertJsonPath('data.persona_slug', 'fish-kenyan')
            ->assertJsonPath('data.resolved_persona.slug', 'fish-kenyan')
            ->assertJsonPath('data.resolved_from', 'user');
        $member->refresh();
        $this->assertSame('fish-kenyan', $member->voice_persona_slug);
        // jsonb column read back: Postgres does not preserve key order.
        $this->assertEquals(['theme' => 'dark', 'atlas_speak' => true, 'atlas_browser_fallback' => false], $member->preferences);

        // Partial update leaves the persona alone; unknown and malformed slugs are refused.
        $this->api($member)->putJson('/api/me/voice', ['speak_enabled' => false])->assertOk()->assertJsonPath('data.persona_slug', 'fish-kenyan');
        $this->api($member)->putJson('/api/me/voice', ['persona_slug' => 'nobody'])->assertStatus(422)->assertJsonPath('reason', 'unknown_persona');
        $this->api($member)->putJson('/api/me/voice', ['persona_slug' => 'Bad Slug!'])->assertStatus(422);
        $this->api($member)->putJson('/api/me/voice', ['persona_slug' => null])->assertOk()->assertJsonPath('data.persona_slug', null);

        // Tenant default: admins only; other settings survive.
        $this->tenant->forceFill(['settings' => ['brand' => ['colour' => 'teal']]])->save();
        $this->api($member)->putJson('/api/admin/tenant/voice-default', ['persona_slug' => 'eleven-leah'])->assertForbidden();
        $this->api()->putJson('/api/admin/tenant/voice-default', ['persona_slug' => 'eleven-leah'])
            ->assertOk()->assertJsonPath('data.default_persona', 'eleven-leah')->assertJsonPath('data.persona.slug', 'eleven-leah');
        $this->assertEquals(['brand' => ['colour' => 'teal'], 'voice' => ['default_persona' => 'eleven-leah']], $this->tenant->fresh()->settings);
        $this->api($member)->getJson('/api/me/voice')->assertJsonPath('data.resolved_from', 'tenant')->assertJsonPath('data.resolved_persona.slug', 'eleven-leah');

        $this->api()->putJson('/api/admin/tenant/voice-default', ['persona_slug' => 'nobody'])->assertStatus(422);
        $this->api()->putJson('/api/admin/tenant/voice-default', [])->assertStatus(422);
        $this->api()->putJson('/api/admin/tenant/voice-default', ['persona_slug' => null])->assertOk();
        $this->assertEquals(['brand' => ['colour' => 'teal'], 'voice' => []], $this->tenant->fresh()->settings);
    }
}
