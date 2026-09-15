<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\OpsDivergenceAlertJob;
use App\Jobs\ReplayDivergenceSweepJob;
use App\Models\Tenant;
use App\Services\DagExecutionService;
use App\Services\EventStore;
use App\Services\NodeActionRunner;
use App\Services\ReplayDivergenceService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * DagExecutionService appends flow.* events in EventStore's short form: the
 * aggregate is "flow" with a fresh random aggregate_id, so the execution is
 * only named in payload.execution_id. Replay has to fold those real events
 * back into the live state, or every checked execution reads as diverged and
 * the ten-minute sweep warns and alerts about it forever.
 */
class ReplayDivergenceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Replay Co',
            'slug' => 'replay-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ])->id;
    }

    public function test_a_completed_run_replays_clean(): void
    {
        $execution = $this->runFlow(fn (): array => ['ok' => true]);

        $this->assertSame('completed', $execution['status']);
        $this->assertReplaysClean($execution['execution_id']);
    }

    public function test_a_run_that_exhausts_its_retries_replays_clean(): void
    {
        $execution = $this->runFlow($this->blockedActions());

        // Four attempts at step_1 (each a fresh node_started), then the node
        // and the execution fail together; the report node never starts.
        $this->assertSame('failed', $execution['status']);
        $this->assertSame(
            ['report' => 'pending', 'step_1' => 'failed', 'trigger' => 'completed'],
            $this->liveNodeStatuses($execution['execution_id']),
        );
        $this->assertReplaysClean($execution['execution_id']);
        $this->assertSame(0, DB::table('event_log')->where('event_type', 'flow.execution_diverged')->count());
    }

    public function test_a_run_paused_for_approval_replays_clean(): void
    {
        $execution = $this->runFlow(fn (): array => ['ok' => true], approvalOnStep: true);

        $this->assertSame('paused', $execution['status']);
        $this->assertSame('waiting_approval', $this->liveNodeStatuses($execution['execution_id'])['step_1']);
        $this->assertReplaysClean($execution['execution_id']);
    }

    public function test_the_production_sequence_that_alerted_for_six_weeks_replays_clean(): void
    {
        // The 2026-07-08 run that prod warned about every ten minutes: the
        // agent keeps blocking, retries restart nodes, late attempts land
        // after a failure, and the report node never starts.
        $executionId = (string) Str::uuid();
        $events = app(EventStore::class);
        $events->append($this->tenantId, 'flow.execution_started', [
            'execution_id' => $executionId,
            'flow_id' => (string) Str::uuid(),
            'context' => [],
        ]);

        foreach ([
            ['flow.node_started', 'trigger'],
            ['flow.node_completed', 'trigger'],
            ['flow.node_started', 'step_1'],
            ['flow.node_completed', 'step_1'],
            ['flow.node_started', 'step_2'],
            ['flow.node_completed', 'step_2'],
            ['flow.node_started', 'step_3'],
            ['flow.node_started', 'step_3'],
            ['flow.execution_failed', 'step_3'],
            ['flow.node_started', 'step_3'],
            ['flow.node_started', 'step_2'],
            ['flow.node_completed', 'step_2'],
            ['flow.node_started', 'step_3'],
            ['flow.execution_failed', 'step_3'],
        ] as [$eventType, $nodeId]) {
            $events->append($this->tenantId, $eventType, match ($eventType) {
                'flow.execution_failed' => [
                    'execution_id' => $executionId,
                    'failed_node' => $nodeId,
                    'error' => 'Agent reported blocked: No tweet content provided.',
                ],
                'flow.node_completed' => [
                    'execution_id' => $executionId,
                    'node_id' => $nodeId,
                    'result' => ['action' => 'agent_step'],
                ],
                default => [
                    'execution_id' => $executionId,
                    'node_id' => $nodeId,
                    'node_type' => 'action',
                ],
            });
        }

        DB::table('flow_executions')->insert([
            'id' => $executionId,
            'tenant_id' => $this->tenantId,
            'flow_id' => (string) Str::uuid(),
            'status' => 'failed',
            'context' => json_encode([]),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['trigger', 'completed', 0],
            ['step_1', 'completed', 1],
            ['step_2', 'completed', 2],
            ['step_3', 'failed', 3],
            ['report', 'pending', 0],
        ] as [$nodeId, $status, $retryCount]) {
            DB::table('execution_dag_nodes')->insert([
                'id' => (string) Str::uuid(),
                'execution_id' => $executionId,
                'tenant_id' => $this->tenantId,
                'node_id' => $nodeId,
                'node_type' => 'action',
                'label' => $nodeId,
                'config' => json_encode([]),
                'status' => $status,
                'retry_count' => $retryCount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertReplaysClean($executionId);
    }

    public function test_live_state_that_contradicts_the_events_is_still_reported(): void
    {
        $execution = $this->runFlow(fn (): array => ['ok' => true]);
        $this->setNodeStatus($execution['execution_id'], 'step_1', 'failed');

        $report = app(ReplayDivergenceService::class)->detectDivergence($this->tenantId, $execution['execution_id']);

        $this->assertSame('diverged', $report['status']);
        $this->assertSame(
            [['type' => 'node_status_mismatch', 'node_id' => 'step_1', 'live' => 'failed', 'replay' => 'completed']],
            $report['divergences'],
        );
    }

    public function test_the_sweep_warns_once_per_divergence_and_stops_writing_reports(): void
    {
        $executionId = $this->runFlow($this->blockedActions())['execution_id'];

        Bus::fake([OpsDivergenceAlertJob::class]);
        Log::spy();

        // The live row claims the report node ran, but it never started.
        $this->travel(1)->minutes();
        $this->setNodeStatus($executionId, 'report', 'completed');
        $this->sweep();
        $this->travel(10)->minutes();
        $this->sweep();

        Bus::assertDispatchedTimes(OpsDivergenceAlertJob::class, 1);
        Log::shouldHaveReceived('warning')->with('Replay divergence detected', Mockery::type('array'))->once();
        $this->assertSame(['clean', 'diverged'], $this->reportStatuses($executionId));

        // Back in line with the events: one clean report, no alert, then quiet.
        $this->travel(10)->minutes();
        $this->setNodeStatus($executionId, 'report', 'pending');
        $this->sweep();
        $this->travel(10)->minutes();
        $this->sweep();

        Bus::assertDispatchedTimes(OpsDivergenceAlertJob::class, 1);
        $this->assertSame(['clean', 'diverged', 'clean'], $this->reportStatuses($executionId));
    }

    /**
     * Runs trigger -> step_1 (action) -> report (log) through the real
     * executor, with node actions answered by $respond.
     *
     * @return array<string, mixed>
     */
    private function runFlow(Closure $respond, bool $approvalOnStep = false): array
    {
        $this->app->instance(NodeActionRunner::class, new class($respond) extends NodeActionRunner
        {
            public function __construct(private readonly Closure $respond) {}

            public function run(string $tenantId, string $nodeType, array $nodeConfig, array $context = []): array
            {
                return ($this->respond)($nodeType);
            }
        });

        $flowId = (string) Str::uuid();
        DB::table('flows')->insert([
            'id' => $flowId,
            'tenant_id' => $this->tenantId,
            'name' => 'Replay probe',
            'slug' => 'replay-probe-'.Str::lower(Str::random(8)),
            'dag' => json_encode([
                'nodes' => [
                    ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                    ['id' => 'step_1', 'type' => 'action', 'config' => ['approval_required' => $approvalOnStep]],
                    ['id' => 'report', 'type' => 'log', 'config' => []],
                ],
                'edges' => [
                    ['from' => 'trigger', 'to' => 'step_1'],
                    ['from' => 'step_1', 'to' => 'report'],
                ],
            ]),
            'triggers' => json_encode([]),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return app(DagExecutionService::class)->createExecution($this->tenantId, $flowId);
    }

    private function blockedActions(): Closure
    {
        return function (string $nodeType): array {
            if ($nodeType === 'action') {
                throw new RuntimeException('Agent reported blocked');
            }

            return ['ok' => true];
        };
    }

    private function assertReplaysClean(string $executionId): void
    {
        $report = app(ReplayDivergenceService::class)->detectDivergence($this->tenantId, $executionId);

        $this->assertSame([], $report['divergences']);
        $this->assertSame('clean', $report['status']);
    }

    /**
     * @return array<string, string>
     */
    private function liveNodeStatuses(string $executionId): array
    {
        return DB::table('execution_dag_nodes')
            ->where('execution_id', $executionId)
            ->orderBy('node_id')
            ->pluck('status', 'node_id')
            ->all();
    }

    private function setNodeStatus(string $executionId, string $nodeId, string $status): void
    {
        DB::table('execution_dag_nodes')
            ->where('execution_id', $executionId)
            ->where('node_id', $nodeId)
            ->update(['status' => $status]);
    }

    private function sweep(): void
    {
        (new ReplayDivergenceSweepJob)->handle(app(ReplayDivergenceService::class));
    }

    /**
     * @return list<string>
     */
    private function reportStatuses(string $executionId): array
    {
        return DB::table('replay_divergence_reports')
            ->where('execution_id', $executionId)
            ->orderBy('created_at')
            ->pluck('status')
            ->all();
    }
}
