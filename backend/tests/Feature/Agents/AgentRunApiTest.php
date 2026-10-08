<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\PackEntitlement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * /api/agent-runs, /api/artifacts, /api/agent-workspaces and the
 * /api/skills/{slug}/run entry point: shapes, status codes, filters,
 * tenant isolation and the error mapping (404 / 402 / 422 / 503 / 409).
 */
class AgentRunApiTest extends AgentsTestCase
{
    public function test_start_show_trace_and_list_a_run(): void
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());

        $started = $this->api()->postJson('/api/agent-runs', ['skill' => 'cold-email-drafting', 'inputs' => $this->defaultInputs()]);
        $started->assertStatus(202)->assertJsonPath('data.status', AgentRun::STATUS_SUCCEEDED)->assertJsonPath('data.skill_slug', 'cold-email-drafting');
        $runId = (string) $started->json('data.run_id');
        $this->assertNotEmpty($started->json('data.workspace_id'));
        $this->assertNotEmpty($started->json('data.approval_id'));

        $show = $this->api()->getJson("/api/agent-runs/{$runId}")->assertOk();
        $show->assertJsonPath('data.id', $runId)
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.trigger_type', 'api')
            ->assertJsonPath('data.mode', 'single_shot')
            ->assertJsonPath('data.inputs.campaign', 'Spring Launch');
        $this->assertCount(4, $show->json('data.artifacts'));
        $this->assertNotEmpty($show->json('data.steps'));
        $this->assertNotEmpty($show->json('data.brain_snapshot_hash'));
        $this->assertSame(['define_reply_rules', 'propose_follow_up_cadence'], collect($show->json('data.next_steps'))->pluck('id')->sort()->values()->all());
        $this->assertSame((string) $this->admin->id, (string) $show->json('data.triggered_by'));

        $trace = $this->api()->getJson("/api/agent-runs/{$runId}/trace")->assertOk();
        $this->assertSame($runId, $trace->json('data.run_id'));
        $kinds = collect($trace->json('data.steps'))->pluck('kind')->unique()->values()->all();
        foreach (['prompt', 'model', 'validator', 'tool_call', 'tool_result'] as $kind) {
            $this->assertContains($kind, $kinds);
        }
        $seqs = collect($trace->json('data.steps'))->pluck('seq')->all();
        $this->assertSame(range(1, count($seqs)), $seqs);

        $this->api()->getJson('/api/agent-runs?status=succeeded&skill=cold-email-drafting')->assertOk()->assertJsonCount(1, 'data');
        $this->api()->getJson('/api/agent-runs?status=failed')->assertOk()->assertJsonCount(0, 'data');
        $this->api()->getJson('/api/agent-runs?skill=nope')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unknown_skill_disabled_runtime_and_missing_entitlement_map_to_404_503_and_402(): void
    {
        $this->seedBrain();

        $this->api()->postJson('/api/agent-runs', ['skill' => 'no-such-skill'])
            ->assertStatus(404)->assertJsonPath('error', 'skill_not_found');

        config()->set('agents.runtime_enabled', false);
        $this->api()->postJson('/api/agent-runs', ['skill' => 'cold-email-drafting', 'inputs' => $this->defaultInputs()])
            ->assertStatus(503)->assertJsonPath('error', 'agents_runtime_disabled');
        config()->set('agents.runtime_enabled', true);

        PackEntitlement::where('tenant_id', $this->tenant->id)->delete();
        $this->api()->postJson('/api/skills/cold-email-drafting/run', ['inputs' => $this->defaultInputs()])
            ->assertStatus(402)
            ->assertJsonPath('checkout_hint', true)
            ->assertJsonPath('pack_id', 'sales-crm')
            ->assertJsonPath('currency', 'USD');
        $this->assertSame(0, AgentRun::forTenant((string) $this->tenant->id)->count());
    }

    public function test_skill_run_endpoint_answers_200_with_the_approval_when_the_queue_is_sync(): void
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());

        $response = $this->api()->postJson('/api/skills/cold-email-drafting/run', ['inputs' => $this->defaultInputs(), 'automation_level' => 'human_led']);

        $response->assertOk()
            ->assertJsonPath('kind', 'agent')
            ->assertJsonPath('mode', 'single_shot')
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('entry_path', '/skills/cold-email-drafting');
        $this->assertSame($this->approvals('agent_artifact')->sole()->id, $response->json('approval_id'));
        $this->assertSame('/agents/runs/'.$response->json('run_id'), $response->json('run_path'));
    }

    public function test_cancel_a_blocked_run_and_retry_a_failed_one(): void
    {
        $this->seedBrain(withVoice: false);
        $blocked = $this->startRun();
        $this->assertSame(AgentRun::STATUS_BLOCKED, $blocked->status);

        $this->api()->postJson("/api/agent-runs/{$blocked->id}/cancel", ['reason' => 'later'])
            ->assertOk()->assertJsonPath('data.status', AgentRun::STATUS_CANCELLED);
        $this->api()->postJson("/api/agent-runs/{$blocked->id}/cancel")->assertStatus(409);
        $this->assertCount(1, $this->events('agent.run.cancelled'));

        $this->writeBrainFile('brand/voice.md', $this->voiceSections());
        $this->modelDown();
        $failed = $this->startRun();
        $this->assertSame(AgentRun::STATUS_FAILED, $failed->status);

        $this->model($this->validSequenceCompletion());
        $retried = $this->api()->postJson("/api/agent-runs/{$failed->id}/retry");
        $retried->assertStatus(202)->assertJsonPath('data.status', AgentRun::STATUS_SUCCEEDED);
        $this->assertSame(1, (int) $failed->refresh()->state['retries']);
        $this->assertNull($failed->error);
        $this->assertCount(1, $this->events('agent.run.retried'));

        // A succeeded run cannot be retried.
        $this->api()->postJson("/api/agent-runs/{$failed->id}/retry")->assertStatus(409)->assertJsonPath('error', 'run_not_retryable');
        // Answers only apply to blocked / waiting_input runs.
        $this->api()->postJson("/api/agent-runs/{$failed->id}/answers", ['answers' => [['path' => 'brand/voice.md', 'section' => 'Tone', 'text' => 'x']]])
            ->assertStatus(409)->assertJsonPath('error', 'run_not_answerable');
    }

    public function test_artifacts_list_show_patch_submit_and_apply(): void
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());
        $run = $this->startRun();

        $list = $this->api()->getJson("/api/artifacts?run_id={$run->id}&kind=draft_email")->assertOk();
        $this->assertCount(3, $list->json('data'));
        $this->assertNull($list->json('data.0.content'), 'list rows carry a preview, not the body');
        $this->api()->getJson('/api/artifacts?status=draft')->assertOk()->assertJsonCount(0, 'data');
        $this->api()->getJson('/api/artifacts?status=submitted')->assertOk()->assertJsonCount(4, 'data');

        $sequence = AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_SEQUENCE)->firstOrFail();
        $this->api()->getJson("/api/artifacts/{$sequence->id}")->assertOk()
            ->assertJsonPath('data.kind', 'draft_sequence')
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.meta.step_count', 3);

        // Submit is idempotent on an already-submitted artifact.
        $this->api()->postJson("/api/artifacts/{$sequence->id}/submit")->assertOk()->assertJsonPath('data.approval_id', $sequence->approval_id);
        $this->assertCount(1, $this->approvals('agent_artifact'));

        // Apply needs an approval first.
        $this->api()->postJson("/api/artifacts/{$sequence->id}/apply")->assertStatus(409)->assertJsonPath('error', 'artifact_not_approved');

        $this->approve($sequence->approval_id);
        $this->api()->postJson("/api/artifacts/{$sequence->id}/apply")->assertOk()->assertJsonPath('data.status', 'applied');
        $this->api()->patchJson("/api/artifacts/{$sequence->id}", ['content' => 'nope'])->assertStatus(409)->assertJsonPath('error', 'artifact_frozen');
    }

    public function test_workspaces_board_and_detail(): void
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());
        $run = $this->startRun();

        $board = $this->api()->getJson('/api/agent-workspaces')->assertOk();
        $this->assertCount(1, $board->json('data'));
        $row = $board->json('data.0');
        $this->assertSame('growth', $row['slug']);
        $this->assertSame('needs_review', $row['status']);
        $this->assertSame('sales_crm_growth', $row['agent']['slug']);
        $this->assertSame(1, $row['pending_reviews']);
        $this->assertSame(1, $row['run_counts']['succeeded']);
        $this->assertSame('cold-email-drafting', $row['skills'][0]['skill_slug']);
        $this->assertGreaterThan(0, $row['spent_today_usd']);

        $detail = $this->api()->getJson('/api/agent-workspaces/growth')->assertOk();
        $this->assertSame($run->id, $detail->json('data.recent_runs.0.id'));
        $this->assertCount(4, $detail->json('data.drafts'));
        $this->assertSame('workspaces/growth/drafts', $detail->json('data.drafts_root'));
        $this->api()->getJson('/api/agent-workspaces/nope')->assertStatus(404);
    }

    public function test_runs_and_artifacts_are_tenant_isolated(): void
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());
        $run = $this->startRun();
        $artifact = AgentArtifact::where('run_id', $run->id)->firstOrFail();

        $other = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Other', 'slug' => 'other-'.Str::lower(Str::random(6)), 'status' => 'active',
            'plan' => 'growth', 'automation_level' => 'assisted', 'onboarding_completed_at' => now(), 'settings' => [],
        ]);
        $stranger = User::create([
            'name' => 'Stranger', 'email' => 'stranger@'.Str::lower(Str::random(8)).'.test', 'password' => bcrypt('pw'),
            'tenant_id' => $other->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        $this->actingAs($stranger, 'sanctum')->getJson("/api/agent-runs/{$run->id}")->assertStatus(404);
        $this->actingAs($stranger, 'sanctum')->getJson("/api/artifacts/{$artifact->id}")->assertStatus(404);
        $this->actingAs($stranger, 'sanctum')->postJson("/api/agent-runs/{$run->id}/cancel")->assertStatus(404);
        $this->actingAs($stranger, 'sanctum')->getJson('/api/agent-runs')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($stranger, 'sanctum')->getJson('/api/agent-workspaces')->assertOk()->assertJsonCount(0, 'data');
    }
}
