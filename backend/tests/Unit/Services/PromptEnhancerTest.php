<?php

namespace Tests\Unit\Services;

use App\Services\PromptEnhancer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PromptEnhancerTest — exercises both modes (inference + deterministic).
 *
 * Runs without Laravel boot for the deterministic path; the inference path
 * requires the Http facade so we use Laravel's TestCase indirectly via
 * the Http::fake() helper in the feature test (AtlasEnhancePromptTest).
 */
class PromptEnhancerTest extends TestCase
{
    public function test_empty_input_returns_noop(): void
    {
        $enhancer = new PromptEnhancer();
        $out = $enhancer->enhance('   ');

        $this->assertSame('noop', $out['mode']);
        $this->assertStringContainsString('nothing to enhance', strtolower(implode(' ', $out['notes'])));
    }

    public function test_deterministic_fallback_structure(): void
    {
        // Ensure no inference_url is configured so we hit the deterministic branch
        config(['services.inference.url' => null]);

        $enhancer = new PromptEnhancer();
        $out = $enhancer->enhance('summarise yesterday\'s usage', [
            'mode'    => 'balanced',
            'surface' => 'atlas_chat',
        ]);

        $this->assertSame('deterministic', $out['mode']);
        $this->assertStringContainsString('1) Objective', $out['enhanced']);
        $this->assertStringContainsString('7) Evaluation criteria', $out['enhanced']);
        $this->assertStringContainsString("summarise yesterday's usage", $out['enhanced']);
    }

    public function test_deep_mode_adds_replay_plan(): void
    {
        config(['services.inference.url' => null]);
        $enhancer = new PromptEnhancer();
        $out = $enhancer->enhance('migrate tenants', ['mode' => 'deep']);

        $this->assertStringContainsString('replay verification plan', $out['enhanced']);
    }

    public function test_agent_builder_surface_mentions_agent_runtime(): void
    {
        config(['services.inference.url' => null]);
        $enhancer = new PromptEnhancer();
        $out = $enhancer->enhance('helpful assistant', ['surface' => 'agent_builder']);

        $this->assertStringContainsString('Agent specification', $out['enhanced']);
    }

    public function test_original_preserved_in_response(): void
    {
        config(['services.inference.url' => null]);
        $enhancer = new PromptEnhancer();
        $out = $enhancer->enhance('  run the daily brief  ');

        $this->assertSame('  run the daily brief  ', $out['original']);
        $this->assertSame('deterministic', $out['mode']);
    }
}
