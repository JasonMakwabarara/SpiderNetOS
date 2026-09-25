<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\AgentWorkspace;
use App\Models\AwarenessItem;
use App\Models\BrainFile;
use App\Models\BusinessAsset;
use App\Models\MessageTemplate;
use App\Models\TenantSkill;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * The plan's PR 1 end-to-end: a Knowledge brain missing brand/voice.md
 * blocks Cold Email Drafting with the manifest's question; answering it
 * re-queues the run, which reads the brain, drafts a 3-step sequence, and
 * ends in exactly one agent_artifact approval. Approve → templates + asset
 * + brain projection; reject → rejected; model outage → failed, no approval.
 */
class ColdEmailDraftingRunTest extends AgentsTestCase
{
    public function test_missing_voice_blocks_with_the_manifest_question_then_answers_unblock_and_the_run_succeeds(): void
    {
        $this->seedBrain(withVoice: false);
        $this->model($this->validSequenceCompletion());

        $blocked = $this->api()->postJson('/api/skills/cold-email-drafting/run', ['inputs' => $this->defaultInputs()]);
        $blocked->assertStatus(422);
        $runId = (string) $blocked->json('run_id');
        $this->assertNotEmpty($runId);
        $this->assertSame('agent', $blocked->json('kind'));
        $this->assertSame('single_shot', $blocked->json('mode'));
        $this->assertSame('/skills/cold-email-drafting', $blocked->json('entry_path'));

        $missing = (array) $blocked->json('missing_brain');
        $this->assertCount(1, $missing, 'only brand/voice.md#Tone is missing');
        $this->assertSame('brand/voice.md', $missing[0]['path']);
        $this->assertSame('Tone', $missing[0]['section']);
        $this->assertStringContainsString('How should we sound', (string) $missing[0]['question']);

        $run = AgentRun::findOrFail($runId);
        $this->assertSame(AgentRun::STATUS_BLOCKED, $run->status);
        $this->assertNotNull($run->workspace_id);
        $this->assertSame(AgentWorkspace::STATUS_NEEDS_ATTENTION, AgentWorkspace::findOrFail($run->workspace_id)->status);
        $this->assertCount(1, $this->events('agent.run.blocked_missing_knowledge'));
        $this->assertCount(0, $this->generateRequests, 'never a guess: no model call while knowledge is missing');
        if (Schema::hasTable('awareness_items')) {
            $this->assertSame(1, AwarenessItem::forTenant((string) $this->tenant->id)->open()->where('raised_by', 'cold-email-drafting')->count());
        }

        $answered = $this->api()->postJson("/api/agent-runs/{$runId}/answers", ['answers' => [
            ['path' => 'brand/voice.md', 'section' => 'Tone', 'text' => $this->voiceSections()['Tone']],
            ['path' => 'brand/voice.md', 'section' => "Do and don't", 'text' => $this->voiceSections()["Do and don't"]],
        ]]);
        $answered->assertStatus(202);

        $run->refresh();
        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);
        $voice = (string) BrainFile::forTenant((string) $this->tenant->id)->where('path', 'brand/voice.md')->value('content');
        $this->assertStringContainsString('Warm, plain-spoken and direct', $voice, 'the answer landed in the brain');
        $this->assertStringContainsString('candid and generous', $voice);
        $this->assertCount(1, $this->events('agent.run.answered'));

        // The model saw the voice, offer and ICP prose.
        $this->assertCount(1, $this->generateRequests);
        Http::assertSent(function (Request $r) {
            $seen = (string) $r['system_prompt'].' '.(string) $r['prompt'];

            return str_ends_with($r->url(), '/generate')
                && str_contains($seen, 'candid and generous')
                && str_contains($seen, 'Follow-up Engine')
                && str_contains($seen, 'growing service agencies')
                && str_contains($seen, 'Spring Launch');
        });

        // 3 draft emails + 1 sequence, all submitted under ONE approval.
        $artifacts = AgentArtifact::where('run_id', $run->id)->get();
        $this->assertSame(3, $artifacts->where('kind', AgentArtifact::KIND_DRAFT_EMAIL)->count());
        $this->assertSame(1, $artifacts->where('kind', AgentArtifact::KIND_DRAFT_SEQUENCE)->count());
        $this->assertSame(4, $artifacts->where('status', AgentArtifact::STATUS_SUBMITTED)->count());
        $sequence = $artifacts->firstWhere('kind', AgentArtifact::KIND_DRAFT_SEQUENCE);
        $this->assertStringContainsString('/cold-email-drafting/'.$run->id.'/step-1.md', (string) $artifacts->firstWhere('kind', AgentArtifact::KIND_DRAFT_EMAIL)->path);
        $this->assertSame(3, (int) $sequence->meta['step_count']);
        $this->assertSame('a', $sequence->meta['steps'][0]['variants'][0]['key']);
        $this->assertSame('b', $sequence->meta['steps'][0]['variants'][1]['key']);

        $approvals = $this->approvals('agent_artifact');
        $this->assertCount(1, $approvals);
        $this->assertSame('pending', $approvals[0]->status);
        $this->assertSame($sequence->id, $approvals[0]->resource_id);
        $this->assertSame($approvals[0]->id, $run->outputs['approval_id']);
        $this->assertSame($approvals[0]->id, $answered->json('data.approval_id'));
        $context = $this->approvalContext($approvals[0]);
        $this->assertSame('draft_sequence', $context['kind']);
        $this->assertCount(4, $context['artifact_ids']);
        $this->assertSame($run->id, $context['run_id']);

        // Outputs: sequence id, next steps from the card with inputs resolved.
        $this->assertSame($sequence->id, $run->outputs['sequence_id']);
        $next = collect($run->outputs['next_steps']);
        $this->assertTrue($next->contains(fn ($s) => $s['id'] === 'propose_follow_up_cadence' && $s['origin'] === 'card' && $s['state'] === 'proposed'
            && $s['skill'] === 'follow-up-drafting' && $s['inputs']['sequence_id'] === $sequence->id && $s['inputs']['campaign'] === 'Spring Launch'));

        // Events, cost, trace.
        $dispatched = $this->events('agent.dispatched');
        $this->assertCount(1, $dispatched);
        $this->assertSame('php_skill', $dispatched[0]->metadata['runtime']);
        $this->assertSame('run_skill:cold-email-drafting', $dispatched[0]->payload['intent']);
        $this->assertCount(1, $this->events('agent.run.succeeded'));
        $this->assertEqualsWithDelta(0.0004, (float) $run->cost_usd, 0.0000001);
        $this->assertSame(120, (int) $run->tokens);
        $usage = $this->events('usage.recorded')->filter(fn ($e) => ($e->payload['resource_type'] ?? null) === 'agent_run');
        $this->assertCount(1, $usage);
        $this->assertSame($run->id, $usage->first()->payload['metadata']['run_id']);

        $kinds = $run->steps()->pluck('kind')->all();
        foreach (['prompt', 'model', 'validator', 'tool_call', 'tool_result'] as $kind) {
            $this->assertContains($kind, $kinds);
        }
        $this->assertNotEmpty($run->state['brain_snapshot_hash']);
        $this->assertArrayHasKey('brand/voice.md', (array) $run->brain_snapshot);

        $workspace = AgentWorkspace::findOrFail($run->workspace_id);
        $this->assertSame(AgentWorkspace::STATUS_NEEDS_REVIEW, $workspace->status);
        $this->assertSame($run->id, $workspace->last_run_id);
        $this->assertEqualsWithDelta(0.0004, (float) $workspace->spent_today_usd, 0.0000001);

        $skill = TenantSkill::forTenant((string) $this->tenant->id)->where('skill_slug', 'cold-email-drafting')->firstOrFail();
        $this->assertTrue($skill->enabled);
        $this->assertSame('human_led', $skill->autonomy_level);
        $this->assertSame($workspace->id, $skill->workspace_id);
        if (Schema::hasTable('awareness_items')) {
            $this->assertSame(0, AwarenessItem::forTenant((string) $this->tenant->id)->open()->where('raised_by', 'cold-email-drafting')->count());
        }
    }

    public function test_approving_the_sequence_writes_templates_an_asset_and_the_brain_projection(): void
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());
        $run = $this->startRun();
        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);
        $approval = $this->approvals('agent_artifact')->sole();

        $this->approve($approval->id);

        $artifacts = AgentArtifact::where('run_id', $run->id)->get();
        $this->assertSame(4, $artifacts->where('status', AgentArtifact::STATUS_APPLIED)->count());
        $this->assertSame('approved', DB::table('approvals')->where('id', $approval->id)->value('status'));

        $templates = MessageTemplate::forTenant((string) $this->tenant->id)->where('key', 'like', 'outreach.spring-launch.%')->orderBy('key')->get();
        $this->assertSame([
            'outreach.spring-launch.step1.a', 'outreach.spring-launch.step1.b',
            'outreach.spring-launch.step2.a', 'outreach.spring-launch.step2.b',
            'outreach.spring-launch.step3.a', 'outreach.spring-launch.step3.b',
        ], $templates->pluck('key')->all());
        $stepOneA = $templates->firstWhere('key', 'outreach.spring-launch.step1.a');
        $this->assertSame('email', $stepOneA->channel);
        $this->assertSame('sales-crm', $stepOneA->pack_id);
        $this->assertSame('Leads are going cold in your inbox', $stepOneA->subject);
        $this->assertStringContainsString('Follow-up Engine', $stepOneA->body);
        $this->assertSame('The reply that never went out', $templates->firstWhere('key', 'outreach.spring-launch.step1.b')->subject);

        $asset = BusinessAsset::forTenant((string) $this->tenant->id)->where('type', 'message_template')->sole();
        $this->assertSame(AgentArtifact::class, $asset->ref_type);
        $this->assertStringContainsString('Spring Launch', $asset->name);
        $this->assertSame('cold-email-drafting', $asset->created_by);

        $projection = BrainFile::forTenant((string) $this->tenant->id)->where('path', 'offer/approved-sequences.md')->first();
        $this->assertNotNull($projection, 'approved sequence projected into offer/approved-sequences.md');
        $this->assertStringContainsString('Spring Launch', (string) $projection->content);
        $this->assertStringContainsString('Leads are going cold in your inbox', (string) $projection->content);
        $this->assertSame('agent', $projection->source);

        $this->assertCount(1, $this->events('agent.artifact.approved'));
        $this->assertCount(1, $this->events('agent.artifact.applied'));
        $this->assertSame(1, (int) TenantSkill::forTenant((string) $this->tenant->id)->where('skill_slug', 'cold-email-drafting')->value('clean_drafts_count'));
        $this->assertSame(AgentWorkspace::STATUS_IDLE, AgentWorkspace::findOrFail($run->workspace_id)->status);
    }

    public function test_rejecting_the_sequence_marks_every_artifact_rejected_and_writes_nothing(): void
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());
        $run = $this->startRun();
        $approval = $this->approvals('agent_artifact')->sole();

        $this->approve($approval->id, grant: false, reason: 'Too salesy for us.');

        $artifacts = AgentArtifact::where('run_id', $run->id)->get();
        $this->assertSame(4, $artifacts->where('status', AgentArtifact::STATUS_REJECTED)->count());
        $this->assertSame('Too salesy for us.', $artifacts->first()->meta['rejected_reason']);
        $this->assertSame(0, MessageTemplate::forTenant((string) $this->tenant->id)->where('key', 'like', 'outreach.%')->count());
        $this->assertSame(0, BusinessAsset::forTenant((string) $this->tenant->id)->where('type', 'message_template')->count());
        $this->assertNull(BrainFile::forTenant((string) $this->tenant->id)->where('path', 'offer/approved-sequences.md')->first());
        $this->assertCount(1, $this->events('agent.artifact.rejected'));
        $this->assertSame(0, (int) TenantSkill::forTenant((string) $this->tenant->id)->where('skill_slug', 'cold-email-drafting')->value('clean_drafts_count'));
    }

    public function test_an_edit_before_approval_is_applied_and_recorded_as_a_revision(): void
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());
        $run = $this->startRun();
        $approval = $this->approvals('agent_artifact')->sole();
        $stepOne = AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_EMAIL)->get()->first(fn ($a) => (int) $a->meta['n'] === 1);

        $edited = "Subject: Leads are going cold in your inbox\n\n{{first_line}} A shorter opener, in our own words, about the follow-ups that slip.";
        $this->api()->patchJson("/api/artifacts/{$stepOne->id}", ['content' => $edited])->assertOk()
            ->assertJsonPath('data.edited', true);
        $this->assertSame($stepOne->content, AgentArtifact::findOrFail($stepOne->id)->meta['original_content']);
        $this->assertTrue((bool) $this->approvalContext(DB::table('approvals')->where('id', $approval->id)->first())['edited']);

        $this->approve($approval->id);

        $template = MessageTemplate::forTenant((string) $this->tenant->id)->where('key', 'outreach.spring-launch.step1.a')->firstOrFail();
        $this->assertStringContainsString('A shorter opener, in our own words', $template->body);
        $this->assertSame(0, (int) TenantSkill::forTenant((string) $this->tenant->id)->where('skill_slug', 'cold-email-drafting')->value('clean_drafts_count'), 'an edit resets the promotion gate');
        if (Schema::hasTable('artifact_revisions')) {
            $this->assertSame(1, DB::table('artifact_revisions')->where('tenant_id', $this->tenant->id)->count());
        }

        // Resolved artifacts are frozen.
        $this->api()->patchJson("/api/artifacts/{$stepOne->id}", ['content' => 'again'])->assertStatus(409);
    }

    public function test_model_outage_fails_the_run_and_never_creates_an_approval(): void
    {
        $this->seedBrain();
        $this->modelDown(503);

        $run = $this->startRun();

        $this->assertSame(AgentRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('model_error', (string) $run->error);
        $this->assertStringContainsString('503', (string) $run->error);
        $this->assertCount(0, $this->approvals('agent_artifact'));
        $this->assertSame(0, AgentArtifact::where('run_id', $run->id)->count());
        $this->assertCount(1, $this->events('agent.run.failed'));
        $this->assertSame('failed', $run->steps()->where('kind', 'model')->first()->status);
        $this->assertSame(AgentWorkspace::STATUS_NEEDS_ATTENTION, AgentWorkspace::findOrFail($run->workspace_id)->status);
    }

    public function test_invalid_json_is_retried_once_colder_and_content_refusals_are_not(): void
    {
        $this->seedBrain();
        $this->model('Sorry, here is the sequence in prose instead of JSON.', $this->validSequenceCompletion());

        $run = $this->startRun();

        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);
        $this->assertCount(2, $this->generateRequests);
        $this->assertSame(0.6, $this->generateRequests[0]['temperature']);
        $this->assertSame(0.0, $this->generateRequests[1]['temperature']);
        $this->assertStringContainsString('was not valid JSON', (string) $this->generateRequests[1]['prompt']);
        $this->assertTrue((bool) $run->outputs['repaired']);
        $this->assertEqualsWithDelta(0.0008, (float) $run->cost_usd, 0.0000001);
    }

    public function test_duplicate_trigger_ref_returns_the_existing_run(): void
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());

        $first = $this->startRun(triggerRef: 'evt:abc');
        $second = $this->startRun(triggerRef: 'evt:abc');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AgentRun::forTenant((string) $this->tenant->id)->count());
        $this->assertCount(1, $this->generateRequests);
    }
}
