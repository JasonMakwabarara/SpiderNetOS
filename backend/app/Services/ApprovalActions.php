<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Agents\Exceptions\BundleIntegrityException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The action a single-stage decision owes, made durable.
 *
 * record() writes it inside the decision's transaction, so a decision cannot
 * commit without the record that its effect is owed. run() performs it at most
 * once; recover() drains what a crash or a failure left behind. How a record
 * runs depends on what its resource hook does (config/approvals.php
 * `action_delivery`), captured on the record when it is written:
 *
 *   transactional  The hook only writes to this database (a job dispatched
 *                  after commit included). It runs inside one transaction with
 *                  the record — locked, then marked done — so the hook's
 *                  effects and "done" commit together or not at all. A replay
 *                  finds it done and does nothing, which holds even for a hook
 *                  that is not idempotent on its own. A failure rolls the hook
 *                  back and leaves the record pending, to be retried.
 *
 *   external       Everything else, including every type not declared: the
 *                  hook may reach outside this database (outreach_reply sends
 *                  a message), where a rollback cannot follow. The record is
 *                  claimed — `running`, committed — before the hook is called,
 *                  so a process that dies inside the hook leaves `running`,
 *                  not `pending`. Whether the outside effect happened is then
 *                  unknown, so it becomes `uncertain`, for a person to
 *                  reconcile, and is never re-run blind. A record never claimed
 *                  is safe to run: nothing was attempted.
 *
 * `failed` is terminal: a hook that is registered but does not exist, an
 * integrity failure (the bundle does not hold together, or the version
 * moved), or a transactional hook still failing after `action_max_attempts`.
 * A missing hook is never read as "nothing was required".
 *
 * A transactional record can also end `uncertain`: its hook's writes and
 * "done" committed, and then something the hook deferred until after the
 * commit (a job push, say) failed. The record is not re-run — the commit
 * happened — and a person has to see it.
 *
 * Every record that did not end `done` carries a machine-readable `reason`
 * (the REASON_* constants) beside the human `last_error`.
 *
 * run() for an external record must not be called inside a transaction: the
 * claim has to be committed before the hook runs, or a crash rolls it back to
 * `pending` and recovery would run the hook a second time.
 */
class ApprovalActions
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const UNCERTAIN = 'uncertain';

    public const TRANSACTIONAL = 'transactional';

    public const EXTERNAL = 'external';

    public const REASON_HOOK_MISSING = 'hook_missing';

    public const REASON_HOOK_FAILED = 'hook_failed';

    public const REASON_INTEGRITY = 'integrity';

    public const REASON_ATTEMPTS_EXHAUSTED = 'attempts_exhausted';

    public const REASON_AFTER_COMMIT_FAILED = 'after_commit_failed';

    public const REASON_EXTERNAL_FAILED = 'external_failed';

    public const REASON_CLAIM_LAPSED = 'claim_lapsed';

    /** Failures that retrying cannot fix. */
    private const PERMANENT = [BundleIntegrityException::class, ApprovalVersionConflict::class];

    /**
     * Written inside the decision's transaction.
     *
     * @param  array{approval_id: string, approved_version_hash: ?string}  $decision
     */
    public function record(object $approval, bool $granted, ?string $response, array $decision): string
    {
        $id = (string) Str::uuid();
        DB::table('approval_actions')->insert([
            'id' => $id,
            'tenant_id' => (string) $approval->tenant_id,
            'approval_id' => (string) $approval->id,
            'resource_type' => (string) $approval->resource_type,
            'resource_id' => (string) $approval->resource_id,
            'granted' => $granted,
            'response' => $response,
            'decision' => json_encode($decision, JSON_THROW_ON_ERROR),
            'delivery' => self::deliveryFor((string) $approval->resource_type),
            'status' => self::PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Undeclared types are external: the safe assumption is that a hook reaches outside. */
    public static function deliveryFor(string $resourceType): string
    {
        $declared = ((array) config('approvals.action_delivery', []))[$resourceType] ?? null;

        return $declared === self::TRANSACTIONAL ? self::TRANSACTIONAL : self::EXTERNAL;
    }

    /** Perform the action, at most once. Returns the record's status afterwards. */
    public function run(string $actionId): string
    {
        $action = DB::table('approval_actions')->where('id', $actionId)->first();
        if ($action === null) {
            throw new \InvalidArgumentException("Approval action [{$actionId}] not found.");
        }

        // A hook that cannot run is a configuration error, not an effect that
        // happened. Fail the record where it stands, before any claim.
        $problem = $action->status === self::PENDING ? app(ApprovalEngine::class)->resourceHookProblem((string) $action->resource_type) : null;
        if ($problem !== null) {
            DB::table('approval_actions')->where('id', $actionId)->where('status', self::PENDING)->update([
                'status' => self::FAILED,
                'reason' => self::REASON_HOOK_MISSING,
                'last_error' => $problem,
                'updated_at' => now(),
            ]);
            Log::error('Approval action cannot run: its hook is missing', ['action_id' => $actionId, 'problem' => $problem]);

            return (string) DB::table('approval_actions')->where('id', $actionId)->value('status');
        }

        return $action->delivery === self::TRANSACTIONAL
            ? $this->runTransactional($actionId)
            : $this->runExternal($action);
    }

    /**
     * Run what is owed and has not happened; mark external hooks that claimed
     * a record and never reported back as uncertain.
     *
     * Pending records are run however old they are once past $olderThanSeconds
     * — the grace that lets the decision's own run go first. Running one that
     * the decision is also running is safe: a transactional record is locked
     * and re-checked, an external one is claimed by compare-and-set.
     *
     * @return array{completed: int, pending: int, failed: int, uncertain: int, lapsed: int}
     */
    public function recover(int $olderThanSeconds = 60, ?int $leaseSeconds = null, int $limit = 200): array
    {
        $lease = $leaseSeconds ?? (int) config('approvals.action_lease_seconds', 900);
        $counts = ['completed' => 0, 'pending' => 0, 'failed' => 0, 'uncertain' => 0, 'lapsed' => 0];

        $due = DB::table('approval_actions')
            ->where('status', self::PENDING)
            ->where('updated_at', '<=', now()->subSeconds($olderThanSeconds))
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($due as $id) {
            $status = $this->runLogged((string) $id);
            $key = match ($status) {
                self::DONE => 'completed',
                self::FAILED => 'failed',
                self::UNCERTAIN => 'uncertain',
                default => 'pending',
            };
            $counts[$key]++;
        }

        $counts['lapsed'] = DB::table('approval_actions')
            ->where('status', self::RUNNING)
            ->where('claimed_at', '<=', now()->subSeconds($lease))
            ->update([
                'status' => self::UNCERTAIN,
                'reason' => self::REASON_CLAIM_LAPSED,
                'last_error' => "Claimed and never reported back within {$lease}s; whether its effect happened is unknown.",
                'updated_at' => now(),
            ]);

        return $counts;
    }

    /** Records that need a person: failed, or uncertain. */
    public function outstanding(): int
    {
        return DB::table('approval_actions')->whereIn('status', [self::FAILED, self::UNCERTAIN])->count();
    }

    /**
     * run(), for a caller that must not fail because of it — the decision,
     * after its commit, and recovery. A failure to run is logged and leaves
     * the record as it was, which is what recovery looks for.
     */
    public function runLogged(string $actionId): string
    {
        try {
            return $this->run($actionId);
        } catch (\Throwable $e) {
            Log::error('Approval action could not be run; left for recovery', ['action_id' => $actionId, 'error' => $e->getMessage()]);

            return (string) (DB::table('approval_actions')->where('id', $actionId)->value('status') ?? self::PENDING);
        }
    }

    private function runTransactional(string $actionId): string
    {
        // Set once this run has fired the hook and written "done" inside its
        // transaction. An exception after that point came from the COMMIT or
        // from what the hook deferred until after it — not from the hook.
        $marked = false;

        try {
            return DB::transaction(function () use ($actionId, &$marked): string {
                $action = DB::table('approval_actions')->where('id', $actionId)->lockForUpdate()->first();
                if ($action === null || $action->status !== self::PENDING) {
                    return (string) ($action->status ?? self::PENDING);
                }

                $this->fire($action);

                DB::table('approval_actions')->where('id', $actionId)->update([
                    'status' => self::DONE,
                    'attempts' => (int) $action->attempts + 1,
                    'reason' => null,
                    'last_error' => null,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
                $marked = true;

                return self::DONE;
            });
        } catch (\Throwable $e) {
            $status = DB::table('approval_actions')->where('id', $actionId)->value('status');

            // An exception can leave DB::transaction after its COMMIT: the
            // callbacks the hook deferred until after commit run there. Then
            // the hook's writes and "done" are committed and must not be
            // retried, but what the hook deferred did not happen. Ask the
            // database which it was rather than assume a rollback.
            if ($marked && $status === self::DONE) {
                DB::table('approval_actions')->where('id', $actionId)->where('status', self::DONE)->update([
                    'status' => self::UNCERTAIN,
                    'reason' => self::REASON_AFTER_COMMIT_FAILED,
                    'last_error' => 'Committed, then a step deferred until after the commit failed: '.self::describe($e),
                    'updated_at' => now(),
                ]);
                Log::error('Approval action committed, then its after-commit step failed', ['action_id' => $actionId, 'error' => $e->getMessage()]);

                return self::UNCERTAIN;
            }

            // Not this run's record to change: another run finished or failed
            // it (a replay that hit a lock error on a done record, say).
            if ($status !== self::PENDING) {
                Log::warning('Approval action run failed on a record that was not pending', ['action_id' => $actionId, 'status' => $status, 'error' => $e->getMessage()]);

                return (string) $status;
            }

            // The hook's writes rolled back with the transaction: nothing of
            // this attempt survived, so the record can be tried again unless
            // the failure is one no retry can fix.
            $permanent = self::isPermanent($e);
            DB::table('approval_actions')->where('id', $actionId)->where('status', self::PENDING)->update([
                'attempts' => DB::raw('attempts + 1'),
                'reason' => $permanent ? self::REASON_INTEGRITY : self::REASON_HOOK_FAILED,
                'last_error' => self::describe($e),
                'updated_at' => now(),
            ]);
            $attempts = (int) DB::table('approval_actions')->where('id', $actionId)->value('attempts');
            $exhausted = ! $permanent && $attempts >= (int) config('approvals.action_max_attempts', 5);
            $terminal = $permanent || $exhausted;
            if ($terminal) {
                DB::table('approval_actions')->where('id', $actionId)->where('status', self::PENDING)->update([
                    'status' => self::FAILED,
                    'reason' => $permanent ? self::REASON_INTEGRITY : self::REASON_ATTEMPTS_EXHAUSTED,
                    'updated_at' => now(),
                ]);
            }
            Log::warning('Approval action failed', ['action_id' => $actionId, 'attempts' => $attempts, 'terminal' => $terminal, 'error' => $e->getMessage()]);

            return $terminal ? self::FAILED : self::PENDING;
        }
    }

    private function runExternal(object $action): string
    {
        // Claimed, and committed, before anything can leave this system.
        $claimed = DB::table('approval_actions')
            ->where('id', $action->id)
            ->where('status', self::PENDING)
            ->update([
                'status' => self::RUNNING,
                'claimed_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);
        if ($claimed !== 1) {
            return (string) DB::table('approval_actions')->where('id', $action->id)->value('status');
        }

        try {
            $this->fire($action);
        } catch (\Throwable $e) {
            // What the hook did outside before it failed is unknown.
            DB::table('approval_actions')->where('id', $action->id)->where('status', self::RUNNING)->update([
                'status' => self::UNCERTAIN,
                'reason' => self::REASON_EXTERNAL_FAILED,
                'last_error' => self::describe($e),
                'updated_at' => now(),
            ]);
            Log::warning('Approval action with an external effect failed; its outcome is uncertain', ['action_id' => $action->id, 'error' => $e->getMessage()]);

            return self::UNCERTAIN;
        }

        DB::table('approval_actions')->where('id', $action->id)->where('status', self::RUNNING)->update([
            'status' => self::DONE,
            'reason' => null,
            'last_error' => null,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        return self::DONE;
    }

    private function fire(object $action): void
    {
        app(ApprovalEngine::class)->fireResourceHook(
            (string) $action->resource_type,
            (string) $action->tenant_id,
            (string) $action->resource_id,
            (bool) $action->granted,
            (string) ($action->response ?? ''),
            (array) json_decode((string) $action->decision, true),
        );
    }

    private static function isPermanent(\Throwable $e): bool
    {
        foreach (self::PERMANENT as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private static function describe(\Throwable $e): string
    {
        return mb_substr($e::class.': '.$e->getMessage(), 0, 2000);
    }
}
