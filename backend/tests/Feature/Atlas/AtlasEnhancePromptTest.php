<?php

namespace Tests\Feature\Atlas;

use App\Services\FeatureFlag;
use App\Services\PromptEnhancer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * AtlasEnhancePromptTest — verifies controller behaviour (flag gate, validation,
 * surface hints) without hitting the sanctum middleware stack.
 *
 * The controller is instantiated directly and called with a Request so these
 * tests do not require a full HTTP round trip. The full middleware stack is
 * exercised in the smoke test at the bottom of this file (skipped on SQLite).
 */
class AtlasEnhancePromptTest extends TestCase
{
    public function test_returns_503_when_flag_off(): void
    {
        FeatureFlag::set('atlas.enhance_prompt', 'off');

        $req = Request::create('/api/atlas/enhance-prompt', 'POST', [
            'prompt' => 'anything',
        ]);

        $controller = app(\App\Http\Controllers\AtlasController::class);
        /** @var JsonResponse $resp */
        $resp = $controller->enhancePrompt($req, app(PromptEnhancer::class));

        $this->assertSame(503, $resp->getStatusCode());
        $this->assertSame('enhance_prompt_disabled', $resp->getData(true)['error']);

        FeatureFlag::forget('atlas.enhance_prompt');
    }

    public function test_returns_enhanced_payload_when_flag_on(): void
    {
        FeatureFlag::set('atlas.enhance_prompt', 'on');
        config(['services.inference.url' => null]);  // force deterministic branch

        $req = Request::create('/api/atlas/enhance-prompt', 'POST', [
            'prompt'  => 'summarise yesterdays usage',
            'surface' => 'atlas_chat',
            'mode'    => 'balanced',
        ]);

        $controller = app(\App\Http\Controllers\AtlasController::class);
        /** @var JsonResponse $resp */
        $resp = $controller->enhancePrompt($req, app(PromptEnhancer::class));

        $this->assertSame(200, $resp->getStatusCode());
        $data = $resp->getData(true);
        $this->assertSame('deterministic', $data['mode']);
        $this->assertSame('atlas_chat', $data['surface']);
        $this->assertArrayHasKey('enhanced', $data);
        $this->assertArrayHasKey('latency_ms', $data);
        $this->assertStringContainsString('Objective', $data['enhanced']);

        FeatureFlag::forget('atlas.enhance_prompt');
    }

    public function test_rejects_empty_prompt_validation(): void
    {
        FeatureFlag::set('atlas.enhance_prompt', 'on');

        $req = Request::create('/api/atlas/enhance-prompt', 'POST', [
            'prompt' => '',
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Http\Controllers\AtlasController::class)
            ->enhancePrompt($req, app(PromptEnhancer::class));

        FeatureFlag::forget('atlas.enhance_prompt');
    }

    public function test_rejects_invalid_surface(): void
    {
        FeatureFlag::set('atlas.enhance_prompt', 'on');

        $req = Request::create('/api/atlas/enhance-prompt', 'POST', [
            'prompt'  => 'valid',
            'surface' => 'weird_surface',
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Http\Controllers\AtlasController::class)
            ->enhancePrompt($req, app(PromptEnhancer::class));

        FeatureFlag::forget('atlas.enhance_prompt');
    }
}
