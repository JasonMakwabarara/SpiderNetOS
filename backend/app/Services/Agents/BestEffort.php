<?php

declare(strict_types=1);

namespace App\Services\Agents;

use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A write whose failure must not fail the work around it — and must not
 * poison it either.
 *
 * Catching the exception is not enough on Postgres. A failed statement inside
 * a transaction aborts the whole transaction: every later statement fails with
 * "current transaction is aborted", and COMMIT quietly becomes ROLLBACK.
 * SQLite has no such state, which is why a swallowed failure looked harmless
 * in every local test. Running the write in its own transaction — a savepoint
 * when one is already open — rolls back that write alone, and the enclosing
 * work carries on.
 *
 * A deadlock is rethrown rather than swallowed: it is not this write's failure
 * but the enclosing transaction's, which Postgres has already aborted.
 */
final class BestEffort
{
    /**
     * @template T
     *
     * @param  callable(): T  $write
     * @param  array<string, mixed>  $context
     * @return T|null null when the write failed
     */
    public static function attempt(callable $write, string $message, array $context = [], string $level = 'warning'): mixed
    {
        try {
            return DB::transaction(static fn () => $write());
        } catch (DeadlockException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::log($level, $message, $context + ['error' => $e->getMessage()]);

            return null;
        }
    }
}
