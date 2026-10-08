<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Services\Agents\BestEffort;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * What a savepoint buys, on the only database where it matters.
 *
 * On Postgres a failed statement aborts the transaction it runs in: every
 * later statement fails with "current transaction is aborted", and COMMIT
 * becomes ROLLBACK. SQLite has no such state, so on SQLite this test would
 * pass with or without the savepoint and prove nothing — it skips there, and
 * the CI step that runs the `postgres-only` group fails on a skip.
 */
#[Group('postgres-only')]
class BestEffortTest extends TestCase
{
    public function test_a_failed_best_effort_write_does_not_abort_the_transaction_around_it(): void
    {
        if (config('database.connections.'.config('database.default').'.driver') !== 'pgsql') {
            $this->markTestSkipped('Only Postgres aborts a transaction on a failed statement; elsewhere this proves nothing.');
        }

        DB::beginTransaction();
        try {
            $result = BestEffort::attempt(
                fn () => DB::select('select * from a_table_that_does_not_exist'),
                'expected failure in BestEffortTest',
            );
            $this->assertNull($result, 'the failure was absorbed');

            // Without the savepoint this statement is refused: the enclosing
            // transaction would already be aborted.
            $this->assertSame(1, (int) DB::selectOne('select 1 as one')->one);
        } finally {
            DB::rollBack();
        }
    }

    public function test_the_value_of_a_successful_write_is_returned(): void
    {
        $this->assertSame(2, BestEffort::attempt(fn () => 2, 'unused'));
    }
}
