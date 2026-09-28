<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\BusinessAsset;
use App\Services\Agents\ApplicationPayload;
use App\Services\Agents\Exceptions\BundleIntegrityException;
use App\Services\ApprovalActions;
use App\Services\ApprovalDecision;
use App\Services\ApprovalEngine;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The action a single-stage decision owes is recorded with the decision, run
 * at most once, and recovered when nothing ran it (ApprovalActions).
 *
 * A process that dies between the decision's commit and its action is modelled
 * in-process by a runner that returns at that point without running anything.
 * ApprovalRaceTest kills a real process there, on Postgres.
 *
 * The policy cases use a probe hook registered for two made-up resource
 * types: one declared transactional, one left undeclared, which makes it
 * external. The probe appends an event before it (optionally) throws, so a
 * rolled-back attempt is visible as a missing event.
 */
class ApprovalActionsTest extends AgentsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ProbeHook::reset();
        config()->set('approvals.resource_hooks.probe_tx', [ProbeHook::class, 'onApprovalResolved']);
        config()->set('approvals.resource_hooks.probe_ext', [ProbeHook::class, 'onApprovalResolved']);
        config()->set('approvals.action_delivery.probe_tx', ApprovalActions::TRANSACTIONAL);
        config()->set('approvals.action_max_attempts', 3);
    }

    // ------------------------------------------------------------------ //
    //  The acceptance path, on the real agent_artifact hook
    // ------------------------------------------------------------------ //

    /**
     * The approver saw a specific version and approved it; the decision
     * committed and nothing ran its action. Recovery applies exactly the
     * version that was approved, once, and a second recovery changes nothing.
     */
    public function test_a_decision_whose_action_never_ran_is_applied_once_by_recovery(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $seen = $this->shownVersion($approval->id);

        $this->dieAfterCommit();
        $this->api()->postJson("/api/approvals/{$approval->id}/approve", ['version_hash' => $seen])
            ->assertStatus(202)
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('action.status', ApprovalActions::PENDING);

        $this->assertSame('approved', DB::table('approvals')->where('id', $approval->id)->value('status'));
        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_SUBMITTED)->count(), 'nothing applied yet');
        $this->assertSame(0, BusinessAsset::forTenant((string) $this->tenant->id)->count());
        $this->assertSame(
            [ApprovalActions::PENDING, ApprovalActions::TRANSACTIONAL, 0],
            [$this->action($approval->id)->status, $this->action($approval->id)->delivery, (int) $this->action($approval->id)->attempts],
        );

        $this->assertSame(1, $this->recover()['completed']);

        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_APPLIED)->count());
        $this->assertSame(1, BusinessAsset::forTenant((string) $this->tenant->id)->count());
        $this->assertSame($seen, DB::table('approvals')->where('id', $approval->id)->value('approved_version_hash'));
        $this->assertSame($seen, ApplicationPayload::hash(ApplicationPayload::for($this->sequenceOf($run))), 'what was applied is what was approved');
        $this->assertSame([ApprovalActions::DONE, 1], [$this->action($approval->id)->status, (int) $this->action($approval->id)->attempts]);

        $this->assertSame(0, $this->recover()['completed'], 'a replayed recovery finds nothing owed');
        $this->assertCount(1, $this->events('agent.artifact.applied'));
        $this->assertSame(1, BusinessAsset::forTenant((string) $this->tenant->id)->count());
    }

    public function test_a_decision_that_runs_its_action_reports_it_done(): void
    {
        [, $approval] = $this->pendingSequence();

        $this->api()->postJson("/api/approvals/{$approval->id}/approve", ['version_hash' => $this->shownVersion($approval->id)])
            ->assertOk()
            ->assertJsonPath('action.status', ApprovalActions::DONE);

        $action = $this->action($approval->id);
        $this->assertSame([ApprovalActions::DONE, 1], [$action->status, (int) $action->attempts]);
        $this->assertSame(
            ['approval_id' => (string) $approval->id, 'approved_version_hash' => $this->shownVersion($approval->id)],
            json_decode((string) $action->decision, true),
            'the record carries the decision the hook is told about',
        );
    }

    // ------------------------------------------------------------------ //
    //  Transactional
    // ------------------------------------------------------------------ //

    public function test_a_transactional_action_runs_once_however_often_it_is_replayed(): void
    {
        $decision = $this->decideProbe('probe_tx');

        $this->assertSame(ApprovalActions::DONE, $decision->actionStatus);
        $actions = app(ApprovalActions::class);
        $this->assertSame(ApprovalActions::DONE, $actions->run($decision->actionId));
        $this->assertSame(ApprovalActions::DONE, $actions->run($decision->actionId));

        $this->assertCount(1, ProbeHook::$calls);
        $this->assertCount(1, $this->events('probe.hook_ran'));
    }

    /** A failed attempt leaves nothing of itself behind, and the action stays owed until it succeeds. */
    public function test_a_failing_transactional_action_rolls_its_hook_back_and_is_retried(): void
    {
        ProbeHook::$throw = new \RuntimeException('downstream table locked');
        $decision = $this->decideProbe('probe_tx');

        $this->assertSame(ApprovalActions::PENDING, $decision->actionStatus);
        $this->assertCount(0, $this->events('probe.hook_ran'), 'the attempt rolled back, the hook\'s event with it');
        $action = $this->action($decision->event->payload['approval_id']);
        $this->assertSame(1, (int) $action->attempts);
        $this->assertStringContainsString('downstream table locked', (string) $action->last_error);

        $this->assertSame(1, $this->recover()['pending'], 'still failing: still owed');
        ProbeHook::$throw = null;
        $this->assertSame(1, $this->recover()['completed']);

        $this->assertSame([ApprovalActions::DONE, 3], [$this->action($decision->event->payload['approval_id'])->status, (int) $this->action($decision->event->payload['approval_id'])->attempts]);
        $this->assertCount(3, ProbeHook::$calls);
        $this->assertCount(1, $this->events('probe.hook_ran'), 'only the attempt that committed left anything');
    }

    public function test_a_transactional_action_that_keeps_failing_is_failed_after_its_attempts(): void
    {
        ProbeHook::$throw = new \RuntimeException('still broken');
        $decision = $this->decideProbe('probe_tx');
        $this->recover();
        $this->assertSame(1, $this->recover()['failed'], 'third attempt of three');

        $this->assertSame(0, $this->recover()['failed'] + $this->recover()['pending'], 'a failed action is not picked up again');
        $this->assertCount(3, ProbeHook::$calls);
        $this->assertSame(ApprovalActions::FAILED, $this->action($decision->event->payload['approval_id'])->status);
        $this->artisan('approvals:recover-actions', ['--older-than' => 0])->assertExitCode(1);
    }

    public function test_an_integrity_failure_is_failed_at_once(): void
    {
        ProbeHook::$throw = new BundleIntegrityException('a child belongs to another run');
        $decision = $this->decideProbe('probe_tx');

        $this->assertSame(ApprovalActions::FAILED, $decision->actionStatus);
        $this->recover();
        $this->assertCount(1, ProbeHook::$calls, 'retrying cannot fix integrity, so it is not retried');
    }

    // ------------------------------------------------------------------ //
    //  External
    // ------------------------------------------------------------------ //

    /** What it did outside before failing is unknown, so it is never re-run blind. */
    public function test_an_external_action_that_fails_is_uncertain_and_never_rerun(): void
    {
        ProbeHook::$throw = new \RuntimeException('provider timed out');
        $decision = $this->decideProbe('probe_ext');

        $this->assertSame([ApprovalActions::UNCERTAIN, ApprovalActions::EXTERNAL], [$decision->actionStatus, $this->action($decision->event->payload['approval_id'])->delivery]);
        ProbeHook::$throw = null;
        $this->recover();

        $this->assertCount(1, ProbeHook::$calls);
        $this->artisan('approvals:recover-actions', ['--older-than' => 0])->assertExitCode(1);
    }

    /** A record never claimed means nothing was attempted: running it is safe. */
    public function test_an_external_action_never_claimed_is_run_once_by_recovery(): void
    {
        $this->dieAfterCommit();
        $decision = $this->decideProbe('probe_ext');
        $this->assertSame(ApprovalActions::PENDING, $decision->actionStatus);
        $this->assertCount(0, ProbeHook::$calls);

        $this->assertSame(1, $this->recover()['completed']);
        $this->recover();

        $this->assertCount(1, ProbeHook::$calls);
        $this->assertSame(ApprovalActions::DONE, $this->action($decision->event->payload['approval_id'])->status);
        $this->artisan('approvals:recover-actions', ['--older-than' => 0])->assertExitCode(0);
    }

    /** A claim with no report back: the process died inside the hook. */
    public function test_an_external_claim_that_never_reported_back_lapses_to_uncertain(): void
    {
        $this->dieAfterCommit();
        $decision = $this->decideProbe('probe_ext');
        DB::table('approval_actions')->where('id', $decision->actionId)->update([
            'status' => ApprovalActions::RUNNING, 'claimed_at' => now()->subSeconds(1000), 'attempts' => 1,
        ]);

        $counts = $this->recover(lease: 900);

        $this->assertSame(1, $counts['lapsed']);
        $this->assertSame(ApprovalActions::UNCERTAIN, $this->action($decision->event->payload['approval_id'])->status);
        $this->assertCount(0, ProbeHook::$calls, 'not re-run');
    }

    // ------------------------------------------------------------------ //
    //  Declarations
    // ------------------------------------------------------------------ //

    /**
     * Undeclared means external. And a declaration must name a hook that
     * exists: `transactional` is a claim about a specific hook, and a claim
     * about nothing would be kept silently.
     */
    public function test_delivery_declarations_name_real_hooks_and_undeclared_types_are_external(): void
    {
        $this->assertSame(ApprovalActions::EXTERNAL, ApprovalActions::deliveryFor('outreach_reply'));
        $this->assertSame(ApprovalActions::EXTERNAL, ApprovalActions::deliveryFor('payment'));
        $this->assertSame(ApprovalActions::TRANSACTIONAL, ApprovalActions::deliveryFor('agent_artifact'));

        $declared = require base_path('config/approvals.php');
        foreach ($declared['action_delivery'] as $type => $delivery) {
            $this->assertContains($delivery, [ApprovalActions::TRANSACTIONAL, ApprovalActions::EXTERNAL], $type);
            $this->assertArrayHasKey($type, $declared['resource_hooks'], "{$type} is declared {$delivery} but has no registered hook");
            $this->assertTrue(class_exists($declared['resource_hooks'][$type][0]), "{$type}'s hook class exists");
        }
    }

    // ------------------------------------------------------------------ //
    //  helpers
    // ------------------------------------------------------------------ //

    /** The decision commits; the process "dies" where it would run the action. */
    private function dieAfterCommit(): void
    {
        $this->app->instance(ApprovalActions::class, new class extends ApprovalActions
        {
            public function runLogged(string $actionId): string
            {
                return self::PENDING;
            }
        });
    }

    /** @return array{completed: int, pending: int, failed: int, uncertain: int, lapsed: int} */
    private function recover(?int $lease = null): array
    {
        $this->app->instance(ApprovalActions::class, $actions = new ApprovalActions);

        return $actions->recover(0, $lease);
    }

    private function decideProbe(string $type): ApprovalDecision
    {
        $engine = app(ApprovalEngine::class);
        $approval = $engine->createApproval((string) $this->tenant->id, (string) $this->admin->id, 'manual', $type, (string) Str::uuid(), 'probe');

        return $engine->decideSingleStage((string) $this->tenant->id, $approval['id'], $this->admin, true, null);
    }

    private function action(string $approvalId): object
    {
        return DB::table('approval_actions')->where('approval_id', $approvalId)->sole();
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

    private function sequenceOf(AgentRun $run): AgentArtifact
    {
        return AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_SEQUENCE)->sole();
    }
}

/** A hook that writes, then optionally fails. */
final class ProbeHook
{
    /** @var list<array{resource_id: string, granted: bool, decision: array<string, mixed>}> */
    public static array $calls = [];

    public static ?\Throwable $throw = null;

    public static function reset(): void
    {
        self::$calls = [];
        self::$throw = null;
    }

    /** @param array<string, mixed> $decision */
    public function onApprovalResolved(string $tenantId, string $resourceId, bool $granted, string $response = '', array $decision = []): void
    {
        self::$calls[] = ['resource_id' => $resourceId, 'granted' => $granted, 'decision' => $decision];
        app(EventStore::class)->append($tenantId, 'probe', $resourceId, 'probe.hook_ran', ['approval_id' => $decision['approval_id'] ?? null]);
        if (self::$throw !== null) {
            throw self::$throw;
        }
    }
}
