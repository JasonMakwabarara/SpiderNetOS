<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\TenantSkill;
use App\Services\Agents\AgentRunService;
use App\Services\Agents\Collaborators;
use App\Services\Agents\RunContext;
use App\Services\Agents\RunContextFactory;
use App\Services\Tools\ToolCatalogue;
use App\Services\Tools\ToolGateway;
use Tests\Feature\Agents\AgentsTestCase;
use Tests\Feature\Agents\Support\FakeCircuitBreaker;
use Tests\Feature\Agents\Support\FakeTool;

/**
 * ToolGateway::call — flag → allowlist → breaker → autonomy ladder →
 * budget → execute → trace, with the PR 1 rule that only the core
 * brain/drafts tools work while agents.tools is off.
 */
class ToolGatewayTest extends AgentsTestCase
{
    private ToolCatalogue $catalogue;

    private FakeTool $write;

    private FakeTool $read;

    private FakeTool $send;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalogue = new ToolCatalogue;
        $this->write = new FakeTool('crm.update_stage', 'write');
        $this->read = new FakeTool('test.read', 'read');
        $this->send = new FakeTool('messages.send', 'send');
        foreach ([$this->write, $this->read, $this->send] as $tool) {
            $this->catalogue->register($tool);
        }
        $this->app->instance(ToolCatalogue::class, $this->catalogue);
        $this->seedBrain();
    }

    /** A queued run (nothing dispatched) with the fake tools allowlisted through tenant overrides. */
    private function context(string $autonomy = TenantSkill::AUTONOMY_HUMAN_LED, array $allow = ['crm.update_stage', 'test.read', 'messages.send']): RunContext
    {
        $run = app(AgentRunService::class)->create((string) $this->tenant->id, 'cold-email-drafting', $this->defaultInputs(), AgentRun::TRIGGER_MANUAL, null, (string) $this->admin->id);
        $this->assertSame(AgentRun::STATUS_QUEUED, $run->status);

        TenantSkill::forTenant((string) $this->tenant->id)->where('skill_slug', 'cold-email-drafting')
            ->update(['autonomy_level' => $autonomy, 'tool_overrides' => json_encode(['allow' => $allow])]);

        return app(RunContextFactory::class)->forRun($run->refresh());
    }

    private function gateway(): ToolGateway
    {
        return app(ToolGateway::class);
    }

    public function test_a_tool_outside_the_allowlist_is_denied_and_traced(): void
    {
        $ctx = $this->context(allow: []);

        $result = $this->gateway()->call($ctx, 'brain.search', ['query' => 'engine']);

        $this->assertFalse($result['success']);
        $this->assertSame('not_in_allowlist', $result['error']);
        $this->assertTrue($result['denied']);
        $step = AgentRunStep::where('run_id', $ctx->run->id)->where('kind', 'tool_call')->sole();
        $this->assertSame('denied', $step->status);
        $this->assertSame('brain.search', $step->name);
        $this->assertCount(1, $this->events('agent.tool.denied'));

        $this->assertSame('unknown_tool', $this->gateway()->call($ctx, 'nope.tool')['error']);
        // Card tools and post_actions form the allowlist (drafts.save_sequence comes from post_actions).
        $this->assertContains('drafts.save_sequence', $ctx->allowlist);
        $this->assertContains('brain.read', $ctx->allowlist);
        $this->assertNotContains('brain.search', $ctx->allowlist);
    }

    public function test_human_led_write_tool_creates_an_approval_and_parks_the_run(): void
    {
        $this->flags(['agents.tools' => 'on']);
        $ctx = $this->context(TenantSkill::AUTONOMY_HUMAN_LED);

        $result = $this->gateway()->call($ctx, 'crm.update_stage', ['stage' => 'qualified']);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['awaiting_approval']);
        $approval = $this->approvals('agent_tool_call')->sole();
        $this->assertSame($approval->id, $result['approval_id']);
        $this->assertSame('pending', $approval->status);
        $this->assertSame($ctx->run->id, $approval->resource_id);
        $context = $this->approvalContext($approval);
        $this->assertSame('crm.update_stage', $context['tool']);
        $this->assertSame('write', $context['risk']);
        $this->assertSame(['stage' => 'qualified'], $context['params']);

        $run = $ctx->run->refresh();
        $this->assertSame(AgentRun::STATUS_WAITING_APPROVAL, $run->status);
        $this->assertSame('crm.update_stage', $run->state['pending_tool_call']['tool']);
        $this->assertSame([], $this->write->calls);
        $this->assertSame('pending', AgentRunStep::where('run_id', $run->id)->where('kind', 'approval')->sole()->status);
        $this->assertCount(1, $this->events('agent.tool.awaiting_approval'));

        // A human said yes: the same call runs with the ladder bypassed.
        $approved = $this->gateway()->call($ctx, 'crm.update_stage', ['stage' => 'qualified'], ['approved' => true]);
        $this->assertTrue($approved['success']);
        $this->assertSame([['stage' => 'qualified']], $this->write->calls);
        $this->assertCount(1, $this->events('agent.tool.completed'));
    }

    public function test_the_ladder_lets_assisted_write_but_gates_sends_and_autonomous_gates_only_irreversible(): void
    {
        $this->flags(['agents.tools' => 'on', 'agents.tools.send' => 'on', 'agents.tools.irreversible' => 'on']);

        $assisted = $this->context(TenantSkill::AUTONOMY_ASSISTED);
        $this->assertTrue($this->gateway()->call($assisted, 'crm.update_stage', ['stage' => 'engaged'])['success']);
        $this->assertTrue($this->gateway()->call($assisted, 'messages.send', ['to' => 'x'])['awaiting_approval'] ?? false);

        $this->catalogue->register(new FakeTool('payments.refund', 'irreversible'));
        $autonomous = $this->context(TenantSkill::AUTONOMY_AUTONOMOUS, ['crm.update_stage', 'messages.send', 'payments.refund']);
        $this->assertTrue($this->gateway()->call($autonomous, 'messages.send', ['to' => 'x'])['success']);
        $this->assertTrue($this->gateway()->call($autonomous, 'payments.refund', ['id' => 'x'])['awaiting_approval'] ?? false);
    }

    public function test_a_paused_circuit_breaker_denies_the_call(): void
    {
        $this->flags(['agents.tools' => 'on']);
        $ctx = $this->context(TenantSkill::AUTONOMY_ASSISTED);
        // Trip the breaker after the run exists: a paused breaker also refuses
        // AgentRunService::create (BreakerPausedException), which is a separate gate.
        $breaker = new FakeCircuitBreaker('Pause everything — tripped by Jason at 09:12');
        $this->app->instance(Collaborators::CIRCUIT_BREAKER, $breaker);

        $result = $this->gateway()->call($ctx, 'test.read', []);

        $this->assertFalse($result['success']);
        $this->assertStringStartsWith('breaker_paused:', $result['error']);
        $this->assertSame([], $this->read->calls);
        $this->assertSame('read', end($breaker->checks)['tool_risk']);
        $this->assertSame('cold-email-drafting', end($breaker->checks)['skill_slug']);
        $this->assertCount(1, $this->events('agent.tool.denied'));

        $breaker->reason = null;
        $this->assertTrue($this->gateway()->call($ctx, 'test.read', [])['success']);
    }

    public function test_flag_semantics_core_tools_work_with_agents_tools_off_and_send_needs_its_own_flag(): void
    {
        $ctx = $this->context(TenantSkill::AUTONOMY_AUTONOMOUS);

        // agents.tools off: the core brain/drafts tools still work...
        $read = $this->gateway()->call($ctx, 'brain.read', ['path' => 'offer/offer.md']);
        $this->assertTrue($read['success']);
        $this->assertStringContainsString('Follow-up Engine', $read['data']['content']);
        $this->assertSame('offer/offer.md', $read['data']['path']);
        $this->assertTrue($this->gateway()->call($ctx, 'brain.read', ['path' => 'offer/offer.md', 'section' => 'Proof'])['success']);
        $this->assertSame('not_found', $this->gateway()->call($ctx, 'brain.read', ['path' => 'market/none.md'])['error']);

        // ...but nothing else does.
        $this->assertSame('tools_flag_off', $this->gateway()->call($ctx, 'test.read', [])['error']);
        $this->assertSame('tools_flag_off', $this->gateway()->call($ctx, 'messages.send', ['to' => 'x'])['error']);

        $this->flags(['agents.tools' => 'on']);
        $this->assertTrue($this->gateway()->call($ctx, 'test.read', [])['success']);
        $this->assertSame('send_flag_off', $this->gateway()->call($ctx, 'messages.send', ['to' => 'x'])['error']);

        $this->flags(['agents.tools' => 'on', 'agents.tools.send' => 'on']);
        $this->assertTrue($this->gateway()->call($ctx, 'messages.send', ['to' => 'x'])['success']);
        $this->assertSame([['to' => 'x']], $this->send->calls);
    }

    public function test_budget_exhaustion_denies_the_call_and_a_missing_connector_is_reported(): void
    {
        $this->flags(['agents.tools' => 'on']);
        $ctx = $this->context(TenantSkill::AUTONOMY_ASSISTED, ['test.read', 'hubspot.create_task']);
        $this->catalogue->register(new FakeTool('hubspot.create_task', 'write', 'hubspot'));

        $this->assertSame('connector_not_connected:hubspot', $this->gateway()->call($ctx, 'hubspot.create_task', [])['error']);

        $ctx->addSpend(10.0); // past the card's per_run_usd cap
        $denied = $this->gateway()->call($ctx, 'test.read', []);
        $this->assertSame('per_run_budget_exceeded', $denied['error']);
        $this->assertSame([], $this->read->calls);
    }

    public function test_schemas_and_mcp_manifest_expose_only_the_allowlist(): void
    {
        $schemas = $this->catalogue->jsonSchemas(['brain.read', 'crm.update_stage']);
        $this->assertSame(['brain.read', 'crm.update_stage'], array_column($schemas, 'name'));
        $this->assertSame('read', $schemas[0]['risk']);
        $this->assertSame('object', $schemas[0]['parameters']['type']);

        $manifest = $this->catalogue->mcpManifest(['drafts.save_sequence']);
        $this->assertSame('spidernet-tools', $manifest['name']);
        $this->assertSame('drafts.save_sequence', $manifest['tools'][0]['name']);
        $this->assertArrayHasKey('inputSchema', $manifest['tools'][0]);
        $this->assertFalse($manifest['tools'][0]['annotations']['destructiveHint']);

        $this->assertTrue($this->catalogue->isCore('drafts.submit_for_review'));
        $this->assertFalse($this->catalogue->isCore('crm.update_stage'));
        $this->assertSame(0.0, $this->catalogue->costOf('brain.read'));
        $this->assertSame(0.005, $this->catalogue->costOf('connector.hubspot.create_task'));
    }

    public function test_internal_routes_serve_the_schema_and_execute_through_the_gateway(): void
    {
        config()->set('services.internal.key', 'secret-key');
        $ctx = $this->context(TenantSkill::AUTONOMY_AUTONOMOUS);

        $this->getJson('/api/internal/tools/schema')->assertStatus(401);
        $schema = $this->withHeaders(['X-Internal-Key' => 'secret-key'])->getJson('/api/internal/tools/schema?tools=brain.read')->assertOk();
        $this->assertSame(['brain.read'], array_column($schema->json('data.tools'), 'name'));
        $this->assertContains('drafts.save', $schema->json('data.core'));

        $headers = ['X-Internal-Key' => 'secret-key', 'X-Tenant-Id' => (string) $this->tenant->id];
        $this->withHeaders($headers)->postJson('/api/internal/tools/brain.read/execute', ['run_id' => $ctx->run->id, 'params' => ['path' => 'customers/icp.md']])
            ->assertOk()->assertJsonPath('data.success', true)->assertJsonPath('data.tool', 'brain.read');
        $this->withHeaders($headers)->postJson('/api/internal/tools/brain.search/execute', ['run_id' => $ctx->run->id, 'params' => ['query' => 'x']])
            ->assertStatus(403)->assertJsonPath('data.error', 'not_in_allowlist');
        $this->withHeaders($headers)->postJson('/api/internal/tools/brain.read/execute', ['run_id' => 'missing', 'params' => []])->assertStatus(404);

        $this->withHeaders($headers)->getJson('/api/internal/brain/files/offer/offer.md')->assertOk()->assertJsonPath('data.path', 'offer/offer.md');
        $this->withHeaders($headers)->getJson('/api/internal/brain/files/market/none.md')->assertStatus(404);
    }
}
