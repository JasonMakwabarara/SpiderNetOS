<?php

declare(strict_types=1);

namespace Tests\Feature\Breaker;

use App\Models\AgentRun;
use App\Models\TenantAgentState;
use App\Services\Agents\AgentCircuitBreaker;
use App\Services\Agents\AgentRunner;
use App\Services\Agents\AgentRunService;
use App\Services\Agents\Collaborators;
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

    public function test_rejecting_three_drafts_in_a_row_fires_the_tripwire_and_demotes_the_skill(): void
    {
        $t = (string) $this->tenant->id;
        $this->assertNull(app(AgentCircuitBreaker::class)->isPaused($t, null, 'cold-email-drafting', 'send'));

        $this->seedBrain();

        for ($i = 0; $i < 3; $i++) {
            $this->model($this->validSequenceCompletion(campaign: 'Run '.$i));
            $run = $this->startRun(['campaign' => 'run-'.$i] + $this->defaultInputs());
            $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);

            $approval = $this->approvals('agent_artifact')->sortByDesc('created_at')->first();
            $this->assertNotNull($approval, 'run '.$i.' produced no draft to reject');
            $this->approve((string) $approval->id, grant: false, reason: 'Not our voice.');
        }

        $state = TenantAgentState::forTenant($t)->where('scope', 'skill')->where('scope_id', 'cold-email-drafting')->first();

        $this->assertNotNull($state, 'three rejections in a row must leave a row behind');
        $this->assertSame(TenantAgentState::STATE_DEMOTED, $state->state);
        $this->assertSame('tripwire', $state->tripped_by);
        $this->assertNotNull(app(AgentCircuitBreaker::class)->isPaused($t, null, 'cold-email-drafting', 'send'), 'a demoted skill still drafts, but its sends park');
        $this->assertNull(app(AgentCircuitBreaker::class)->isPaused($t, null, 'cold-email-drafting', 'read'));
    }
}
