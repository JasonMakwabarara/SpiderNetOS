<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\BusinessAsset;
use App\Models\Event;
use App\Models\MessageTemplate;
use App\Models\TenantSkill;
use App\Services\Agents\ApplicationPayload;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

/**
 * Approval races, run as real races.
 *
 * Each competitor is a separate PHP process with its own Postgres session
 * (tests/Concurrency/approval-worker.php). Sequential calls, or two calls on
 * one connection, cannot establish locking behaviour: the second simply sees
 * the first's committed result. So the overlap is forced and then verified.
 * The test takes a row lock on the contested rows itself, starts both
 * competitors, and waits until Postgres reports both sessions waiting on a
 * lock — which means each has already read the row as `pending` and is inside
 * its write. Only then is the lock released and the competitors let go
 * together.
 *
 * The fixtures are committed, not wrapped in the usual test transaction: an
 * independent session cannot see rows inside another session's uncommitted
 * transaction, so RefreshDatabase's wrapper is switched off here and the
 * tenant's rows are purged in tearDown instead.
 *
 * Postgres only. On any other driver the tests skip, and the CI step that
 * runs the `postgres-only` group passes --fail-on-skipped and
 * --fail-on-empty-test-suite, so a lane that silently ran none of them fails
 * rather than reading as a pass.
 */
#[Group('postgres-only')]
class ApprovalRaceTest extends AgentsTestCase
{
    private const HOLDER = 'race_holder';

    /** Every observable consequence of one approval, applied once. */
    private const APPROVED_ONCE = [
        'approval' => 'approved', 'approval.granted' => 1, 'approval.rejected' => 0,
        'agent.artifact.approved' => 1, 'agent.artifact.applied' => 1, 'agent.artifact.rejected' => 0,
        'artifacts' => ['applied' => 4], 'business_assets' => 1, 'clean_drafts' => 1,
    ];

    /** Every observable consequence of one rejection. */
    private const REJECTED_ONCE = [
        'approval' => 'rejected', 'approval.granted' => 0, 'approval.rejected' => 1,
        'agent.artifact.approved' => 0, 'agent.artifact.applied' => 0, 'agent.artifact.rejected' => 1,
        'artifacts' => ['rejected' => 4], 'business_assets' => 0, 'clean_drafts' => 0,
    ];

    /** @var list<Process> */
    private array $workers = [];

    protected function connectionsToTransact(): array
    {
        return $this->onPostgres() ? [] : parent::connectionsToTransact();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->onPostgres()) {
            $this->markTestSkipped('Approval races need Postgres: SQLite serialises every writer, so nothing here can overlap.');
        }

        $default = (string) config('database.default');
        config()->set('database.connections.'.self::HOLDER, config('database.connections.'.$default));
        DB::purge(self::HOLDER);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        if ($this->onPostgres()) {
            DB::connection(self::HOLDER)->disconnect();
            $this->purgeTenant((string) $this->tenant->id);
        }

        parent::tearDown();
    }

    public function test_two_concurrent_approvals_decide_once_and_apply_once(): void
    {
        [$run, $approval] = $this->pendingSequence();

        $outcomes = $this->race(
            'select id from approvals where id = ? for update', [$approval->id],
            [$this->approveJob($approval->id), $this->approveJob($approval->id)],
        );

        $this->assertSame(self::APPROVED_ONCE, $this->effects($run, $approval->id), 'outcomes: '.json_encode($outcomes));
        $this->assertEqualsCanonicalizing([200, 409], array_column($outcomes, 'status'), json_encode($outcomes));
    }

    public function test_a_concurrent_approve_and_reject_leave_one_terminal_outcome(): void
    {
        [$run, $approval] = $this->pendingSequence();

        $outcomes = $this->race(
            'select id from approvals where id = ? for update', [$approval->id],
            [$this->approveJob($approval->id), $this->approveJob($approval->id, grant: false)],
        );

        // Either may win; what may not happen is both, or a mixture.
        $effects = $this->effects($run, $approval->id);
        $this->assertSame($effects['approval'] === 'approved' ? self::APPROVED_ONCE : self::REJECTED_ONCE, $effects, 'outcomes: '.json_encode($outcomes));
        $this->assertEqualsCanonicalizing([200, 409], array_column($outcomes, 'status'), json_encode($outcomes));
    }

    /**
     * The hook is its own boundary. Two resolution paths can deliver the same
     * decision (the chain and the controller both report), so the hook must
     * apply once even when the approval row is not what serialises them.
     */
    public function test_two_concurrent_hook_deliveries_apply_the_bundle_once(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $ids = AgentArtifact::where('run_id', $run->id)->orderBy('id')->pluck('id')->all();

        // Delivered as the engine delivers it: naming the deciding approval
        // and the version it bound.
        $hook = [
            'mode' => 'hook', 'resource_type' => 'agent_artifact', 'tenant_id' => (string) $this->tenant->id,
            'resource_id' => (string) $approval->resource_id, 'granted' => true,
            'decision' => ['approval_id' => (string) $approval->id, 'approved_version_hash' => $this->shownVersion($approval->id)],
        ];
        $outcomes = $this->race(
            'select id from agent_artifacts where id in ('.implode(',', array_fill(0, count($ids), '?')).') order by id for update', $ids,
            [$hook, $hook],
        );

        // The approval row itself is untouched: only the hook was delivered.
        $this->assertSame(['approval' => 'pending', 'approval.granted' => 0] + self::APPROVED_ONCE, $this->effects($run, $approval->id), 'outcomes: '.json_encode($outcomes));
        $this->assertSame(['returned', 'returned'], array_column($outcomes, 'status'), json_encode($outcomes));
    }

    /**
     * Atomicity under contention. The first request wins the compare-and-set
     * and holds the row; the second queues behind it; the first then fails
     * to write its event and rolls back. The waiter must re-read the row as
     * `pending` and decide it — one committed decision, one application —
     * rather than inherit a half-made one or be told it was already decided.
     *
     * The first competitor fails only after it has seen the second blocked
     * behind it (`saw_waiter`), so the interleaving is the one named here
     * and not a lucky ordering.
     */
    public function test_a_winner_that_rolls_back_leaves_the_decision_to_the_waiter(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $tag = $this->tag();

        $failing = $this->approveJob($approval->id) + ['fail_event' => [
            'type' => 'approval.granted', 'signal' => "{$tag}-0-past-cas", 'await_waiter' => "{$tag}-1",
        ]];
        $this->workers[] = $first = $this->worker($failing, "{$tag}-0");
        $first->start();
        $this->waitForSession("{$tag}-0-past-cas");

        $this->workers[] = $second = $this->worker($this->approveJob($approval->id), "{$tag}-1");
        $second->start();
        $first->wait();
        $second->wait();

        $outcomes = [$this->outcome($first), $this->outcome($second)];
        $this->assertSame(self::APPROVED_ONCE, $this->effects($run, $approval->id), 'outcomes: '.json_encode($outcomes));
        $this->assertSame([500, true], [$outcomes[0]['status'], $outcomes[0]['saw_waiter'] ?? null], json_encode($outcomes[0]));
        $this->assertSame(200, $outcomes[1]['status'], json_encode($outcomes[1]));
    }

    /**
     * An approval and an edit of the same bundle, the edit first. The edit
     * rebinds the approval to a new version; the approval presenting the old
     * one is then refused as stale, and nothing is applied. Whatever order
     * they land in, nothing is applied that was not approved.
     */
    public function test_an_edit_that_lands_first_makes_the_concurrent_approval_stale(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $seen = $this->shownVersion($approval->id);

        $outcomes = $this->race($this->bundleLock($run), $this->bundleIds($run), [
            $this->editJob($run),
            $this->approveJob($approval->id),
        ]);

        $this->assertSame([200, 409], array_column($outcomes, 'status'), json_encode($outcomes));
        $this->assertSame('version_stale', $outcomes[1]['body']['reason'] ?? null, json_encode($outcomes[1]));
        $row = DB::table('approvals')->where('id', $approval->id)->first();
        $this->assertSame('pending', $row->status);
        $this->assertNotSame($seen, $row->version_hash, 'the edit rebound the approval');
        $this->assertSame($row->version_hash, ApplicationPayload::hash(ApplicationPayload::for($this->sequenceOf($run))));
        $this->assertSame(0, BusinessAsset::forTenant((string) $this->tenant->id)->count(), 'nothing applied');
    }

    /**
     * The same race, the approval first. It binds the version it saw and
     * commits; the edit then finds the review decided and is refused; the
     * hook applies exactly the approved payload.
     */
    public function test_an_approval_that_lands_first_freezes_the_concurrent_edit(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $seen = $this->shownVersion($approval->id);

        $outcomes = $this->race($this->bundleLock($run), $this->bundleIds($run), [
            $this->approveJob($approval->id),
            $this->editJob($run),
        ]);

        $this->assertSame([200, 409], array_column($outcomes, 'status'), json_encode($outcomes));
        $this->assertSame('approval_decided', $outcomes[1]['body']['error'] ?? null, json_encode($outcomes[1]));
        $this->assertSame(self::APPROVED_ONCE, $this->effects($run, $approval->id));
        // What was applied is what was approved: the bound version is the
        // payload as it stands, and the edit never reached it.
        $this->assertSame($seen, DB::table('approvals')->where('id', $approval->id)->value('approved_version_hash'));
        $this->assertSame($seen, ApplicationPayload::hash(ApplicationPayload::for($this->sequenceOf($run))));
        $this->assertStringNotContainsString('racing edit', (string) MessageTemplate::forTenant((string) $this->tenant->id)->where('key', 'outreach.spring-launch.step1.a')->value('body'));
    }

    /**
     * Two editors who loaded the same version save at once. The bundle lock
     * orders them; the version check makes the second notice that it started
     * from content that is gone. Exactly one save lands, and it is not
     * silently overwritten.
     */
    public function test_two_concurrent_editors_cannot_overwrite_each_other(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $loaded = $this->shownVersion($approval->id);

        $outcomes = $this->race($this->bundleLock($run), $this->bundleIds($run), [
            $this->editJob($run, 'The first editor wrote this.'),
            $this->editJob($run, 'The second editor wrote this.'),
        ]);

        $this->assertSame([200, 409], array_column($outcomes, 'status'), json_encode($outcomes));
        $this->assertSame('edit_stale', $outcomes[1]['body']['error'] ?? null, json_encode($outcomes[1]));
        $step = AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_EMAIL)->get()->first(fn ($a) => (int) $a->meta['n'] === 1);
        $this->assertStringContainsString('The first editor wrote this.', (string) $step->content);
        $this->assertNotSame($loaded, $this->shownVersion($approval->id), 'the landed save rebound the approval');
    }

    // ------------------------------------------------------------------ //
    //  helpers
    // ------------------------------------------------------------------ //

    private function sequenceOf(AgentRun $run): AgentArtifact
    {
        return AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_SEQUENCE)->sole();
    }

    /** @return list<string> */
    private function bundleIds(AgentRun $run): array
    {
        return AgentArtifact::where('run_id', $run->id)->orderBy('id')->pluck('id')->all();
    }

    private function bundleLock(AgentRun $run): string
    {
        return 'select id from agent_artifacts where id in ('.implode(',', array_fill(0, count($this->bundleIds($run)), '?')).') order by id for update';
    }

    /** @return array<string, mixed> an admin editing step 1 of the run's sequence, from the version its page loaded */
    private function editJob(AgentRun $run, string $content = 'A racing edit of the opener.'): array
    {
        $step = AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_EMAIL)->get()->first(fn ($a) => (int) $a->meta['n'] === 1);

        return [
            'mode' => 'http', 'method' => 'PATCH', 'user_id' => (string) $this->admin->id,
            'uri' => '/api/artifacts/'.$step->id,
            'body' => [
                'content' => "Subject: A racing edit\n\n{{first_line}} {$content}",
                'expected_version' => $this->shownVersion((string) $step->approval_id),
            ],
        ];
    }

    /** A per-test prefix for the competitors' session names. */
    private function tag(): string
    {
        return 'race-'.substr((string) $this->tenant->id, 0, 8);
    }

    private function onPostgres(): bool
    {
        return config('database.connections.'.config('database.default').'.driver') === 'pgsql';
    }

    /** @return array{0: AgentRun, 1: object} a committed run whose sequence awaits approval */
    private function pendingSequence(): array
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());
        $run = $this->startRun();
        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);

        return [$run, $this->approvals('agent_artifact', 'pending')->sole()];
    }

    /** @return array<string, mixed> */
    private function approveJob(string $approvalId, bool $grant = true): array
    {
        return [
            'mode' => 'http', 'user_id' => (string) $this->admin->id,
            'uri' => '/api/approvals/'.$approvalId.($grant ? '/approve' : '/reject'),
            'body' => $grant ? ['version_hash' => $this->shownVersion($approvalId)] : ['reason' => 'Not our voice.'],
        ];
    }

    /**
     * Hold a lock on the contested rows, start the competitors, wait until
     * all of them are blocked on it, then release them together.
     *
     * Started one at a time — each only once the one before is blocked — so
     * the point where they meet is the contested row and nothing earlier.
     * Started together, the original code's two requests met first inside
     * EventStore::append(), which reads an aggregate's version before taking
     * its sequence lock: both computed the same version, the unique index on
     * (aggregate_type, aggregate_id, version) failed one of them with a 500,
     * and the race under test never happened. Staggering loses nothing: every
     * competitor has still read the row as `pending` before any decides.
     *
     * @param  list<mixed>  $bindings
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    private function race(string $lockSql, array $bindings, array $jobs): array
    {
        $tag = $this->tag();
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();

        try {
            $held = $holder->select($lockSql, $bindings);
            $this->assertNotEmpty($held, 'the contested rows exist and are held');
            $holderPid = (int) $holder->selectOne('select pg_backend_pid() as pid')->pid;

            foreach ($jobs as $i => $job) {
                $this->workers[] = $worker = $this->worker($job, "{$tag}-{$i}");
                $worker->start();
                $this->waitUntilQueuedBehind($holderPid, $tag, $i + 1);
            }
        } finally {
            // The holder changed nothing; releasing its lock is the starting gun.
            $holder->rollBack();
        }

        $outcomes = [];
        foreach ($this->workers as $worker) {
            $worker->wait();
            $outcomes[] = $this->outcome($worker);
        }

        return $outcomes;
    }

    /** @param array<string, mixed> $job */
    private function worker(array $job, string $applicationName): Process
    {
        $default = (string) config('database.default');
        $job['config'] = [
            'database.default' => $default,
            'database.connections.'.$default => ['application_name' => $applicationName] + (array) config('database.connections.'.$default),
            'features' => config('features'),
            'agents' => config('agents'),
            'broadcasting.default' => 'null',
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ];

        $process = new Process([PHP_BINARY, base_path('tests/Concurrency/approval-worker.php')], base_path(), [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
        ]);
        $process->setInput((string) json_encode($job));
        $process->setTimeout(120);

        return $process;
    }

    /**
     * Wait until $expected competitors are blocked, and blocked on the right
     * thing. Two sessions "waiting on a lock somewhere" would prove nothing:
     * each named competitor must be blocked by the holder or by a competitor
     * queued ahead of it for the same rows, by nothing else, and the queue
     * must start at the holder.
     */
    private function waitUntilQueuedBehind(int $holderPid, string $tag, int $expected): void
    {
        $deadline = microtime(true) + 60;
        while (true) {
            $waiting = DB::select(
                "select pid, pg_blocking_pids(pid)::text as blockers from pg_stat_activity
                 where application_name like ? and wait_event_type = 'Lock'",
                [$tag.'-%'],
            );
            $blockers = [];
            foreach ($waiting as $row) {
                $blockers[(int) $row->pid] = array_map('intval', array_filter(explode(',', trim((string) $row->blockers, '{}'))));
            }
            $allowed = [$holderPid, ...array_keys($blockers)];
            $onlyOnEachOther = $blockers !== [] && array_filter($blockers, fn (array $b) => $b === [] || array_diff($b, $allowed) !== []) === [];
            $rootedAtHolder = array_filter($blockers, fn (array $b) => in_array($holderPid, $b, true)) !== [];

            if (count($blockers) >= $expected && $onlyOnEachOther && $rootedAtHolder) {
                return;
            }
            $this->failIfAnyCompetitorExited();
            if (microtime(true) > $deadline) {
                $this->fail(sprintf('Only %d of %d competitors queued behind the holder (pid %d) within 60s: %s', count($blockers), $expected, $holderPid, json_encode($blockers)));
            }
            usleep(50_000);
        }
    }

    /** Wait until a session with this name exists, i.e. a competitor has announced a point in its work. */
    private function waitForSession(string $applicationName): void
    {
        $deadline = microtime(true) + 60;
        while ((int) DB::selectOne('select count(*) as n from pg_stat_activity where application_name = ?', [$applicationName])->n === 0) {
            $this->failIfAnyCompetitorExited();
            if (microtime(true) > $deadline) {
                $this->fail("No session named {$applicationName} appeared within 60s.");
            }
            usleep(50_000);
        }
    }

    private function failIfAnyCompetitorExited(): void
    {
        foreach ($this->workers as $worker) {
            if (! $worker->isRunning()) {
                $this->fail('A competitor finished before the race was set, so nothing overlapped: '.json_encode($this->outcome($worker)));
            }
        }
    }

    /** @return array<string, mixed> */
    private function outcome(Process $worker): array
    {
        $output = $worker->getOutput();
        $at = strrpos($output, '@@RESULT@@');
        if ($at === false) {
            return ['status' => 'no-result', 'exit' => $worker->getExitCode(), 'stdout' => mb_substr($output, -2000), 'stderr' => mb_substr($worker->getErrorOutput(), -2000)];
        }

        return (array) json_decode(substr($output, $at + strlen('@@RESULT@@')), true);
    }

    private function approvalEvents(string $approvalId, string $type): int
    {
        return Event::forTenant((string) $this->tenant->id)
            ->where('aggregate_id', $approvalId)->where('event_type', $type)->count();
    }

    /**
     * What the race left behind, compared as one value so a failure shows
     * every duplicated consequence at once rather than the first.
     *
     * @return array<string, mixed>
     */
    private function effects(AgentRun $run, string $approvalId): array
    {
        $tenant = (string) $this->tenant->id;

        return [
            'approval' => DB::table('approvals')->where('id', $approvalId)->value('status'),
            'approval.granted' => $this->approvalEvents($approvalId, 'approval.granted'),
            'approval.rejected' => $this->approvalEvents($approvalId, 'approval.rejected'),
            'agent.artifact.approved' => $this->events('agent.artifact.approved')->count(),
            'agent.artifact.applied' => $this->events('agent.artifact.applied')->count(),
            'agent.artifact.rejected' => $this->events('agent.artifact.rejected')->count(),
            'artifacts' => AgentArtifact::where('run_id', $run->id)->pluck('status')->countBy()->sortKeys()->all(),
            'business_assets' => BusinessAsset::forTenant($tenant)->count(),
            'clean_drafts' => (int) TenantSkill::forTenant($tenant)->where('skill_slug', 'cold-email-drafting')->value('clean_drafts_count'),
        ];
    }

    /**
     * Every row this tenant's committed fixture wrote. Foreign-key checks are
     * suspended for the purge only (session_replication_role, which the
     * disposable CI and local containers' superuser may set), so table order
     * does not matter; rows keyed by run or approval rather than tenant go
     * with them.
     */
    private function purgeTenant(string $tenantId): void
    {
        $runs = DB::table('agent_runs')->where('tenant_id', $tenantId)->pluck('id')->all();
        $approvals = DB::table('approvals')->where('tenant_id', $tenantId)->pluck('id')->all();

        $columns = collect(DB::select(
            "select table_name, column_name from information_schema.columns
             where table_schema = current_schema() and column_name in ('tenant_id', 'run_id', 'approval_id')
               and data_type in ('uuid', 'character varying', 'text')",
        ));

        DB::transaction(function () use ($columns, $tenantId, $runs, $approvals): void {
            DB::statement('SET LOCAL session_replication_role = replica');
            foreach ($columns as $column) {
                $ids = match ($column->column_name) {
                    'tenant_id' => [$tenantId],
                    'run_id' => $runs,
                    default => $approvals,
                };
                if ($ids !== []) {
                    DB::table($column->table_name)->whereIn($column->column_name, $ids)->delete();
                }
            }
            DB::table('tenants')->where('id', $tenantId)->delete();
        });
    }
}
