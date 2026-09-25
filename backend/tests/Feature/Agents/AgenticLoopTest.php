<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Jobs\ResumeAgentRunJob;
use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Services\Agents\AgentRunResumer;
use App\Services\Agents\Collaborators;
use App\Services\Skills\SkillRegistry;
use App\Services\Tools\ToolCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Agents\Support\FakeSkillRegistry;
use Tests\Feature\Agents\Support\FakeTool;

/**
 * The agentic SkillLoop skeleton (tested lightly, PR 2 hardens it): the
 * JSON tool_calls protocol, a read tool executed in-loop, `final` validated
 * and saved through the post action, and a write tool that parks the run
 * on an agent_tool_call approval which resumes it from state.messages.
 */
class AgenticLoopTest extends AgentsTestCase
{
    private FakeTool $writeTool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SkillRegistry::class, new FakeSkillRegistry([FakeSkillRegistry::agenticCard()]));

        $this->writeTool = new FakeTool('crm.update_stage', 'write');
        $catalogue = new ToolCatalogue;
        $catalogue->register($this->writeTool);
        $this->app->instance(ToolCatalogue::class, $catalogue);

        $this->flags(['agents.tools' => 'on']);
        $this->seedBrain();
    }

    public function test_tool_calls_run_in_loop_until_final_which_is_saved_as_a_note(): void
    {
        $this->model(
            ['tool_calls' => [['name' => 'brain.read', 'params' => ['path' => 'offer/offer.md']]]],
            ['final' => ['note' => 'The offer is the Follow-up Engine, a done-for-you follow-up system for small agencies.']],
        );

        $run = $this->startRun(['topic' => 'offer'], null, 'agentic-note-test');

        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);
        $this->assertSame('agentic', $run->mode);
        $this->assertCount(2, $this->generateRequests);
        $this->assertStringContainsString('brain.read', (string) $this->generateRequests[0]['system_prompt'], 'tool schemas are in the system prompt');
        $this->assertStringContainsString('TOOL RESULT (brain.read)', (string) $this->generateRequests[1]['prompt']);
        $this->assertStringContainsString('Follow-up Engine', (string) $this->generateRequests[1]['prompt'], 'the tool result was fed back');

        $steps = $run->steps()->get();
        $this->assertTrue($steps->contains(fn ($s) => $s->kind === 'tool_call' && $s->name === 'brain.read' && $s->status === 'ok'));
        $this->assertTrue($steps->contains(fn ($s) => $s->kind === 'tool_result' && $s->name === 'brain.read' && $s->status === 'ok'));
        $this->assertCount(2, $this->events('agent.tool.invoked')->filter(fn ($e) => in_array($e->payload['tool'], ['brain.read', 'drafts.save'], true)));

        $note = AgentArtifact::where('run_id', $run->id)->sole();
        $this->assertSame(AgentArtifact::KIND_NOTE, $note->kind);
        $this->assertStringContainsString('Follow-up Engine', (string) $note->content);
        $this->assertSame($note->id, $run->outputs['artifact_id']);
        $this->assertSame(2, (int) $run->state['iteration']);
        $this->assertCount(5, $run->state['messages']); // system, user, assistant(tool_calls), tool, assistant(final)
        $this->assertCount(0, $this->approvals('agent_artifact'), 'no post action submits a note for review');
    }

    public function test_a_write_tool_parks_the_run_on_approval_and_the_decision_resumes_it(): void
    {
        $this->model(
            ['tool_calls' => [['name' => 'crm.update_stage', 'params' => ['stage' => 'qualified']]]],
            ['final' => ['note' => 'Moved the lead to qualified and noted why.']],
        );

        $run = $this->startRun(['topic' => 'stage'], null, 'agentic-note-test');

        $this->assertSame(AgentRun::STATUS_WAITING_APPROVAL, $run->status, (string) $run->error);
        $this->assertCount(1, $this->generateRequests);
        $this->assertSame([], $this->writeTool->calls, 'human_led: the write did not execute');
        $approval = $this->approvals('agent_tool_call')->sole();
        $this->assertSame($run->id, $approval->resource_id);
        $this->assertSame('crm.update_stage', $this->approvalContext($approval)['tool']);
        $this->assertSame('crm.update_stage', $run->state['pending_tool_call']['tool']);
        $this->assertSame($approval->id, $run->state['pending_tool_call']['approval_id']);
        $this->assertCount(1, $this->events('agent.tool.awaiting_approval'));
        $this->assertCount(1, $this->events('agent.run.waiting_approval'));
        $this->assertNull($run->claimed_by, 'the slot is released while parked');

        $this->approve($approval->id);

        $run->refresh();
        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);
        $this->assertSame([['stage' => 'qualified']], $this->writeTool->calls, 'approved: the write ran exactly once');
        $this->assertCount(2, $this->generateRequests);
        $this->assertStringContainsString('TOOL RESULT (crm.update_stage)', (string) $this->generateRequests[1]['prompt']);
        $this->assertArrayNotHasKey('pending_tool_call', (array) $run->state);
        $this->assertCount(1, $this->events('agent.run.approval_resolved'));
        $this->assertCount(1, $this->events('agent.run.resumed'));
        $this->assertSame(1, AgentArtifact::where('run_id', $run->id)->count());
    }

    public function test_a_rejected_tool_call_is_fed_back_to_the_model_as_a_refusal(): void
    {
        $this->model(
            ['tool_calls' => [['name' => 'crm.update_stage', 'params' => ['stage' => 'won']]]],
            ['final' => ['note' => 'The stage change was declined by the owner; nothing changed.']],
        );
        $run = $this->startRun(['topic' => 'stage'], null, 'agentic-note-test');
        $approval = $this->approvals('agent_tool_call')->sole();

        $this->approve($approval->id, grant: false, reason: 'Not yet.');

        $run->refresh();
        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);
        $this->assertSame([], $this->writeTool->calls);
        $this->assertStringContainsString('rejected_by_human', (string) $this->generateRequests[1]['prompt']);
        $this->assertNotNull(Collaborators::resolve(Collaborators::SKILL_REGISTRY));
    }

    /**
     * Two resolution paths can deliver one decision (the chain and the
     * controller both report). The resumer records it once, under a lock on
     * the run, and queues one resume — a second would execute the call twice.
     */
    public function test_a_decision_delivered_twice_resumes_the_run_once(): void
    {
        $this->model(['tool_calls' => [['name' => 'crm.update_stage', 'params' => ['stage' => 'qualified']]]]);
        $run = $this->startRun(['topic' => 'stage'], null, 'agentic-note-test');
        $this->assertSame(AgentRun::STATUS_WAITING_APPROVAL, $run->status, (string) $run->error);

        Queue::fake();
        $resumer = app(AgentRunResumer::class);
        $resumer->onApprovalResolved((string) $this->tenant->id, (string) $run->id, true, 'first');
        $resumer->onApprovalResolved((string) $this->tenant->id, (string) $run->id, true, 'second');

        // The job count alone cannot show a second decision: the job is
        // ShouldBeUnique, so its cache lock drops a second dispatch while the
        // first is queued. The event count and the kept response can.
        Queue::assertPushed(ResumeAgentRunJob::class, 1);
        $this->assertCount(1, $this->events('agent.run.approval_resolved'));
        $this->assertSame('first', $run->refresh()->state['pending_tool_call']['response']);
    }

    /**
     * The resume job waits for the decision to commit. Dispatched inside the
     * transaction — as the chain path's is — a sync queue ran it at once, and
     * a rollback after that could not take back a tool call already made.
     */
    public function test_the_resume_waits_for_the_decision_to_commit(): void
    {
        $this->model(['tool_calls' => [['name' => 'crm.update_stage', 'params' => ['stage' => 'qualified']]]]);
        $run = $this->startRun(['topic' => 'stage'], null, 'agentic-note-test');
        $this->assertSame(AgentRun::STATUS_WAITING_APPROVAL, $run->status, (string) $run->error);

        try {
            DB::transaction(function () use ($run): void {
                app(AgentRunResumer::class)->onApprovalResolved((string) $this->tenant->id, (string) $run->id, true);
                throw new \RuntimeException('the enclosing decision rolled back');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->writeTool->calls, 'no resume ran for a decision that never committed');
        $run->refresh();
        $this->assertSame(AgentRun::STATUS_WAITING_APPROVAL, $run->status);
        $this->assertNull($run->state['pending_tool_call']['decision'], 'the parked call is still undecided (parking writes decision: null)');
    }
}
