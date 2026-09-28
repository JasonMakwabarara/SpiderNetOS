<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Races run as real races: each competitor is a separate PHP process with its
 * own Postgres session (tests/Concurrency/race-worker.php). Sequential calls,
 * or two calls on one connection, cannot establish locking behaviour — the
 * second simply sees the first's committed result — so the overlap is forced
 * and then verified. The test takes a lock on the contested rows itself on a
 * second connection (the holder), starts the competitors, waits until Postgres
 * reports each of them blocked behind it, and only then releases them.
 *
 * Fixtures are committed, not wrapped in the usual test transaction: an
 * independent session cannot see rows inside another session's uncommitted
 * transaction, so RefreshDatabase's wrapper is switched off on Postgres and
 * the tenant's rows are purged in tearDown instead.
 *
 * Postgres only. On any other driver the using test skips, and the CI step that
 * runs the `postgres-only` group passes --fail-on-skipped and
 * --fail-on-empty-test-suite, so a lane that silently ran none of them fails
 * rather than reading as a pass.
 */
trait SeparateProcessRaces
{
    /** @var list<Process> */
    protected array $workers = [];

    /** The tenant whose committed rows the race writes, and tearDown purges. */
    abstract protected function raceTenantId(): string;

    /**
     * RefreshDatabase's own default (the default connection) except on
     * Postgres, where the fixtures must be committed. A class that uses both
     * traits directly resolves the collision in this one's favour.
     */
    protected function connectionsToTransact(): array
    {
        return $this->onPostgres() ? [] : [config('database.default')];
    }

    protected function holder(): string
    {
        return 'race_holder';
    }

    /** Call from setUp, after parent::setUp(). */
    protected function bootRaces(string $whyPostgres): void
    {
        if (! $this->onPostgres()) {
            $this->markTestSkipped($whyPostgres);
        }

        $default = (string) config('database.default');
        config()->set('database.connections.'.$this->holder(), config('database.connections.'.$default));
        DB::purge($this->holder());
    }

    /** Call from tearDown, before parent::tearDown(). */
    protected function shutDownRaces(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        if ($this->onPostgres()) {
            DB::connection($this->holder())->disconnect();
            $this->purgeTenant($this->raceTenantId());
        }
    }

    /** A per-test prefix for the competitors' session names. */
    protected function tag(): string
    {
        return 'race-'.substr($this->raceTenantId(), 0, 8);
    }

    protected function onPostgres(): bool
    {
        return config('database.connections.'.config('database.default').'.driver') === 'pgsql';
    }

    /**
     * Hold a lock on the contested rows, start the competitors, wait until
     * all of them are blocked on it, then release them together.
     *
     * Started one at a time — each only once the one before is blocked — so
     * the point where they meet is the contested lock and nothing earlier, and
     * they are released in the order they queued. Every competitor has still
     * done all its work up to the lock before any proceeds past it.
     *
     * @param  list<mixed>  $bindings
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    protected function race(string $lockSql, array $bindings, array $jobs): array
    {
        $tag = $this->tag();
        $holder = DB::connection($this->holder());
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
    protected function worker(array $job, string $applicationName): Process
    {
        $default = (string) config('database.default');
        $job['config'] = [
            'database.default' => $default,
            'database.connections.'.$default => ['application_name' => $applicationName] + (array) config('database.connections.'.$default),
            'features' => config('features'),
            'agents' => config('agents'),
            'approvals' => config('approvals'),
            'broadcasting.default' => 'null',
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ];

        $process = new Process([PHP_BINARY, base_path('tests/Concurrency/race-worker.php')], base_path(), [
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
    protected function waitUntilQueuedBehind(int $holderPid, string $tag, int $expected): void
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
    protected function waitForSession(string $applicationName): void
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

    protected function failIfAnyCompetitorExited(): void
    {
        foreach ($this->workers as $worker) {
            if (! $worker->isRunning()) {
                $this->fail('A competitor finished before the race was set, so nothing overlapped: '.json_encode($this->outcome($worker)));
            }
        }
    }

    /** @return array<string, mixed> */
    protected function outcome(Process $worker): array
    {
        $output = $worker->getOutput();
        $at = strrpos($output, '@@RESULT@@');
        if ($at === false) {
            return ['status' => 'no-result', 'exit' => $worker->getExitCode(), 'stdout' => mb_substr($output, -2000), 'stderr' => mb_substr($worker->getErrorOutput(), -2000)];
        }

        return (array) json_decode(substr($output, $at + strlen('@@RESULT@@')), true);
    }

    /**
     * Every row this tenant's committed fixture wrote. Foreign-key checks are
     * suspended for the purge only (session_replication_role, which the
     * disposable CI and local containers' superuser may set), so table order
     * does not matter; rows keyed by run or approval rather than tenant go
     * with them.
     */
    protected function purgeTenant(string $tenantId): void
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
