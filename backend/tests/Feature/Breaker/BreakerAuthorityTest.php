<?php

declare(strict_types=1);

namespace Tests\Feature\Breaker;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\TenantAgentState;
use App\Services\Agents\AgentCircuitBreaker;
use App\Services\Agents\AgentRunner;
use App\Services\Agents\AgentRunService;
use App\Services\Agents\Collaborators;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Agents\AgentsTestCase;
use Tests\Feature\Agents\Support\UnavailableCircuitBreaker;

/**
 * Who the breaker actually stops, and what happens when it cannot answer.
 *
 * Three gaps this covers, none of which had a test: the resume path ran on the
 * authority that existed when the approval was requested; an unreadable
 * breaker read as "not paused" for every risk; and nothing asserted that
 * resolving an approval fires the tripwire at all.
 */
class BreakerAuthorityTest extends AgentsTestCase
{
    public function test_an_unanswerable_breaker_refuses_a_send_and_still_allows_a_read(): void
    {
        $t = (string) $this->tenant->id;
        $this->app->instance(AgentCircuitBreaker::class, new UnavailableCircuitBreaker);

        $this->assertNull(Collaborators::breakerReason($t, null, 'cold-email-drafting', 'read'));
        $this->assertNull(Collaborators::breakerReason($t, null, 'cold-email-drafting', 'draft'));
        $this->assertNull(Collaborators::breakerReason($t, null, 'cold-email-drafting', 'write'));

        $send = Collaborators::breakerReason($t, null, 'cold-email-drafting', 'send');
        $this->assertNotNull($send, 'a send must not proceed on an unanswered permission check');
        $this->assertStringContainsString('cannot be read', $send);
        $this->assertNotNull(Collaborators::breakerReason($t, null, 'cold-email-drafting', 'irreversible'));
    }

    public function test_a_breaker_that_throws_is_treated_the_same_way(): void
    {
        $t = (string) $this->tenant->id;
        $this->app->instance(AgentCircuitBreaker::class, new UnavailableCircuitBreaker(throwInstead: true));

        $this->assertNull(Collaborators::breakerReason($t, null, 'cold-email-drafting', 'read'));
        $this->assertNotNull(Collaborators::breakerReason($t, null, 'cold-email-drafting', 'send'));
    }

    public function test_a_run_level_check_with_no_risk_degrades_open(): void
    {
        // Dispatch and claim pass toolRisk = null. Refusing those on an
        // unreadable store would take the whole product down over a hiccup,
        // and nothing has left the building at that point.
        $this->app->instance(AgentCircuitBreaker::class, new UnavailableCircuitBreaker);

        $this->assertNull(Collaborators::breakerReason((string) $this->tenant->id, null, 'cold-email-drafting', null));
    }

    public function test_resume_refuses_when_the_breaker_tripped_after_the_approval(): void
    {
        $t = (string) $this->tenant->id;
        $run = app(AgentRunService::class)->create($t, 'cold-email-drafting', $this->defaultInputs(), AgentRun::TRIGGER_MANUAL, null, (string) $this->admin->id);
        $run->forceFill(['status' => AgentRun::STATUS_WAITING_APPROVAL])->save();

        // The human said yes; between the card being shown and the click, the
        // breaker tripped.
        app(AgentCircuitBreaker::class)->pause($t, 'skill', 'cold-email-drafting', 'Stop while we rewrite the offer');

        app(AgentRunner::class)->resume($run);

        $run->refresh();
        $this->assertSame(AgentRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('circuit_breaker_paused', (string) $run->error);
        $this->assertSame([], $this->generateRequests, 'the model was never called, so nothing was paid for or drafted');
    }

    /**
     * The tie, made deliberate rather than waited for.
     *
     * The original selection was `approvals(...)->sortByDesc('created_at')->first()`
     * over every agent_artifact approval for the tenant at any status. With two
     * approvals sharing a second-precision timestamp and one of them already
     * resolved, which row comes back is the database's business - and rejecting
     * a resolved approval is a 409. This asserts the property the repair relies
     * on, without depending on that ordering: the approval acted on is the
     * PENDING one belonging to the run just created.
     */
    public function test_a_tied_timestamp_never_selects_an_already_resolved_approval(): void
    {
        $t = (string) $this->tenant->id;
        $this->seedBrain();

        $runs = [];
        foreach ([0, 1] as $i) {
            $this->model($this->validSequenceCompletion(campaign: 'Tie '.$i));
            $runs[$i] = $this->startRun(['campaign' => 'tie-'.$i] + $this->defaultInputs());
            $this->assertSame(AgentRun::STATUS_SUCCEEDED, $runs[$i]->status, (string) $runs[$i]->error);
        }

        $idOf = static fn (AgentRun $run): string => (string) AgentArtifact::forTenant($t)
            ->where('run_id', $run->id)->whereNotNull('approval_id')->value('approval_id');

        $first = $idOf($runs[0]);
        $second = $idOf($runs[1]);
        $this->assertNotSame('', $first);
        $this->assertNotSame('', $second);
        $this->assertNotSame($first, $second, 'two runs must not share one approval');

        // Force the tie the calendar only sometimes supplies.
        DB::table('approvals')->whereIn('id', [$first, $second])
            ->update(['created_at' => '2026-09-19 10:00:00', 'requested_at' => '2026-09-19 10:00:00']);

        $this->approve($first, grant: false, reason: 'Not our voice.');

        $tied = $this->approvals('agent_artifact')->whereIn('id', [$first, $second]);
        $this->assertCount(2, $tied, 'the scenario needs both approvals present');
        $this->assertCount(1, $tied->pluck('created_at')->unique(), 'the timestamps are not actually tied, so this proves nothing');
        $this->assertSame(1, $tied->where('status', 'pending')->count(), 'exactly one of the tied pair must still be pending');

        // The binding, which is what the repair depends on.
        $selected = $idOf($runs[1]);
        $this->assertSame($second, $selected, 'the run-bound selection drifted off its own run');
        $this->assertSame('pending', $this->approvals('agent_artifact')->firstWhere('id', $selected)->status);

        // And it resolves: a 409 here would mean an already-resolved approval was chosen.
        $this->approve($selected, grant: false, reason: 'Not our voice either.');
        $this->assertSame('rejected', $this->approvals('agent_artifact')->firstWhere('id', $selected)->status);
    }

    public function test_rejecting_three_drafts_in_a_row_fires_the_tripwire_and_demotes_the_skill(): void
    {
        $t = (string) $this->tenant->id;
        $this->assertNull(app(AgentCircuitBreaker::class)->isPaused($t, null, 'cold-email-drafting', 'send'));

        $this->seedBrain();

        $rejected = [];

        for ($i = 0; $i < 3; $i++) {
            $this->model($this->validSequenceCompletion(campaign: 'Run '.$i));
            $run = $this->startRun(['campaign' => 'run-'.$i] + $this->defaultInputs());
            $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);

            // Bind to THIS run's artifact. Selecting the tenant's newest
            // `agent_artifact` approval was neither pending-only nor bound to the
            // run, so a previously resolved approval could be chosen and rejected
            // twice - a 409. It survived on SQLite and failed on Postgres because
            // the two order a second-precision tie differently; the tie is what
            // made the unbound selection reachable, not the cause of it.
            $approvalId = AgentArtifact::forTenant($t)->where('run_id', $run->id)
                ->whereNotNull('approval_id')->value('approval_id');
            $this->assertNotNull($approvalId, 'run '.$i.' produced no draft to reject');

            $approval = $this->approvals('agent_artifact')->firstWhere('id', $approvalId);
            $this->assertNotNull($approval, 'run '.$i.' left an artifact pointing at no approval');
            $this->assertSame('pending', $approval->status, 'run '.$i.' selected an already-resolved approval');

            $this->approve((string) $approvalId, grant: false, reason: 'Not our voice.');
            $rejected[] = (string) $approvalId;
        }

        // Three rejections, not one approval rejected three times.
        $this->assertCount(3, array_unique($rejected), 'the tripwire must see three distinct rejections');

        $state = TenantAgentState::forTenant($t)->where('scope', 'skill')->where('scope_id', 'cold-email-drafting')->first();

        $this->assertNotNull($state, 'three rejections in a row must leave a row behind');
        $this->assertSame(TenantAgentState::STATE_DEMOTED, $state->state);
        $this->assertSame('tripwire', $state->tripped_by);
        $this->assertNotNull(app(AgentCircuitBreaker::class)->isPaused($t, null, 'cold-email-drafting', 'send'), 'a demoted skill still drafts, but its sends park');
        $this->assertNull(app(AgentCircuitBreaker::class)->isPaused($t, null, 'cold-email-drafting', 'read'));
    }
}
