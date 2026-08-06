<?php

declare(strict_types=1);

namespace Tests\Feature\Atlas;

use App\Http\Controllers\AtlasController;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AtlasClarityGate;
use App\Services\AtlasDiscoveryService;
use App\Services\MetaPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * AtlasChatPipelineTest — pins the issue #88 acceptance criteria: the chat
 * endpoint routes free-form messages through the discovery → clarity-gate →
 * MetaPlanner pipeline (LLM plane), never a static command-list fallback,
 * and slash commands keep their fast path.
 *
 * Follows the AtlasEnhancePromptTest convention: the controller is invoked
 * directly so the sanctum/tenant middleware stack stays out of scope.
 */
class AtlasChatPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantAndUser(): array
    {
        $tenant = Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Pipeline Co',
            'slug' => 'pipeline-co-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
        ]);

        $user = User::create([
            'name' => 'Pipeline Operator',
            'email' => 'op-'.Str::lower(Str::random(8)).'@test.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]);

        return [$tenant, $user];
    }

    private function chatRequest(Tenant $tenant, User $user, string $message): Request
    {
        $request = Request::create('/api/atlas/chat', 'POST', ['message' => $message]);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('tenant_id', (string) $tenant->id);
        $request->attributes->set('tenant', $tenant);

        return $request;
    }

    public function test_free_form_message_gets_dynamic_response_not_static_fallback(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser();
        config(['features' => array_merge((array) config('features'), ['atlas.openjarvis' => 'off'])]);

        // Planner stubbed defensively: a fresh tenant normally routes to
        // discovery, but if discovery decides to act the pipeline must land
        // here — never in a canned command list.
        $this->mock(MetaPlanner::class, function ($mock) {
            $mock->shouldReceive('processAtlasRequest')->zeroOrMoreTimes()
                ->andReturn(['status' => 'dispatched', 'agent_id' => 'atlas', 'cost_status' => null]);
            $mock->shouldReceive('parseCommandToAst')->zeroOrMoreTimes()
                ->andReturn(['type' => 'chat']);
        });

        $response = app(AtlasController::class)->chat(
            $this->chatRequest($tenant, $user, 'Where is my business losing the most time right now?'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);

        $this->assertSame('1', $data['contract_version']);
        $this->assertNotSame('', trim((string) $data['message']['contract']['action_summary']));
        $this->assertContains(
            $data['message']['metadata']['mode'],
            ['discover', 'clarify', 'confirm', 'act'],
            'Chat must resolve through the discovery/clarity/planner pipeline.',
        );

    }

    public function test_clear_message_dispatches_through_planner_pipeline(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser();
        config(['features' => array_merge((array) config('features'), ['atlas.openjarvis' => 'off'])]);

        $this->mock(AtlasDiscoveryService::class, function ($mock) {
            $mock->shouldReceive('absorbAnswer')->once();
            $mock->shouldReceive('evaluate')->once()
                ->andReturn(['mode' => 'act', 'profile_pct' => 90]);
        });
        $this->mock(AtlasClarityGate::class, function ($mock) {
            $mock->shouldReceive('assess')->once()
                ->andReturn(['mode' => 'act', 'confidence' => 0.93]);
        });
        $this->mock(MetaPlanner::class, function ($mock) {
            $mock->shouldReceive('processAtlasRequest')->once()->withAnyArgs()
                ->andReturn(['status' => 'dispatched', 'agent_id' => 'atlas', 'cost_status' => ['state' => 'ok']]);
            $mock->shouldReceive('parseCommandToAst')->once()
                ->andReturn(['type' => 'create_flow']);
        });

        $response = app(AtlasController::class)->chat(
            $this->chatRequest($tenant, $user, 'Follow up with every lead that went quiet last week'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);

        $this->assertSame('act', $data['message']['metadata']['mode']);
        $this->assertSame('dispatched', $data['message']['metadata']['status']);
        $this->assertSame('create_flow', $data['message']['metadata']['intent']);
        $this->assertNotSame('', trim((string) $data['message']['contract']['action_summary']));

    }

    public function test_slash_command_keeps_fast_path_past_discovery_and_clarity(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser();
        config(['features' => array_merge((array) config('features'), ['atlas.openjarvis' => 'off'])]);

        $this->mock(AtlasDiscoveryService::class, function ($mock) {
            $mock->shouldReceive('absorbAnswer')->once();
            // Even when discovery wants to interview, a slash command must act.
            $mock->shouldReceive('evaluate')->once()
                ->andReturn(['mode' => 'discover', 'questions' => ['What matters most?'], 'profile_pct' => 5]);
        });
        $this->mock(AtlasClarityGate::class, function ($mock) {
            $mock->shouldNotReceive('assess');
        });
        $this->mock(MetaPlanner::class, function ($mock) {
            $mock->shouldReceive('processAtlasRequest')->once()->withAnyArgs()
                ->andReturn(['status' => 'dispatched', 'agent_id' => 'sentinel', 'cost_status' => null]);
            $mock->shouldReceive('parseCommandToAst')->once()
                ->andReturn(['type' => 'query_status']);
        });

        $response = app(AtlasController::class)->chat(
            $this->chatRequest($tenant, $user, '/status'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);

        $this->assertSame('dispatched', $data['message']['metadata']['status']);
        $this->assertSame('query_status', $data['message']['metadata']['intent']);
        $this->assertSame('sentinel', $data['message']['metadata']['agent_used']);

    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
