<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\BusinessAsset;
use App\Models\TenantSkill;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;
use Mockery;

/**
 * The single-stage decision's own guarantees, in one process. The races
 * themselves are ApprovalRaceTest, on Postgres; these are the properties a
 * race test cannot show — what a failed decision leaves behind, and what a
 * replayed one does.
 */
class SingleStageDecisionTest extends AgentsTestCase
{
    /**
     * The decision and its durable record commit together. If the event
     * cannot be written, the approval must not read `approved` — and the
     * hook, which runs only after a committed decision, must not fire.
     */
    public function test_a_decision_whose_event_cannot_be_recorded_leaves_nothing_behind(): void
    {
        [$run, $approval] = $this->pendingSequence();

        $real = app(EventStore::class);
        $this->instance(EventStore::class, Mockery::mock(EventStore::class, function ($mock) use ($real): void {
            $mock->shouldReceive('append')->andReturnUsing(function (...$args) use ($real) {
                if (($args[3] ?? null) === 'approval.granted') {
                    throw new \RuntimeException('event store unavailable');
                }

                return $real->append(...$args);
            });
        }));

        $this->api()->postJson("/api/approvals/{$approval->id}/approve")->assertStatus(500);

        $row = DB::table('approvals')->where('id', $approval->id)->first();
        $this->assertSame('pending', $row->status, 'the decision rolled back with its event');
        $this->assertNull($row->approver_id);
        $this->assertCount(0, $this->events('approval.granted'));
        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_SUBMITTED)->count(), 'the hook never fired');
        $this->assertSame(0, BusinessAsset::forTenant((string) $this->tenant->id)->count());
    }

    /** A retried request gets the answer already given, and repeats nothing. */
    public function test_a_replayed_approval_repeats_no_effect(): void
    {
        [$run, $approval] = $this->pendingSequence();

        $this->api()->postJson("/api/approvals/{$approval->id}/approve")->assertOk();
        $this->api()->postJson("/api/approvals/{$approval->id}/approve")
            ->assertStatus(409)
            ->assertJson(['error' => 'Approval has already been approved.']);
        $this->api()->postJson("/api/approvals/{$approval->id}/reject", ['reason' => 'too late'])->assertStatus(409);

        $this->assertCount(1, $this->events('approval.granted'));
        $this->assertCount(0, $this->events('approval.rejected'));
        $this->assertCount(1, $this->events('agent.artifact.applied'));
        $this->assertSame(1, BusinessAsset::forTenant((string) $this->tenant->id)->count());
        $this->assertSame(1, (int) TenantSkill::forTenant((string) $this->tenant->id)->where('skill_slug', 'cold-email-drafting')->value('clean_drafts_count'));
    }

    /** @return array{0: AgentRun, 1: object} */
    private function pendingSequence(): array
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());
        $run = $this->startRun();
        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);

        return [$run, $this->approvals('agent_artifact', 'pending')->sole()];
    }
}
