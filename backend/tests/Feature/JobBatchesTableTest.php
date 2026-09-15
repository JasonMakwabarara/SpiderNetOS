<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * queue.batching stores batches in job_batches: without the table,
 * Bus::batch() and queue:prune-batches throw on first use.
 */
class JobBatchesTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_batch_is_stored_run_to_completion_and_pruned(): void
    {
        JobBatchesTableTestJob::$handled = 0;

        $batch = Bus::batch([new JobBatchesTableTestJob, new JobBatchesTableTestJob])->name('probe')->dispatch();
        $stored = Bus::findBatch($batch->id);

        // The sync queue swallows job exceptions, and a cancelled batch also
        // counts as finished, so prove the jobs ran and nothing failed.
        $this->assertSame(2, JobBatchesTableTestJob::$handled);
        $this->assertNotNull($stored);
        $this->assertSame('probe', $stored->name);
        $this->assertSame(2, $stored->totalJobs);
        $this->assertSame(0, $stored->pendingJobs);
        $this->assertSame(0, $stored->failedJobs);
        $this->assertFalse($stored->cancelled());
        $this->assertTrue($stored->finished());

        $this->artisan('queue:prune-batches')->expectsOutputToContain('0 entries deleted.')->assertSuccessful();

        $this->travel(25)->hours();
        $this->artisan('queue:prune-batches')->expectsOutputToContain('1 entries deleted.')->assertSuccessful();
        $this->assertNull(Bus::findBatch($batch->id));
    }

    public function test_the_migration_is_a_no_op_where_the_table_already_exists(): void
    {
        $batch = $this->app->make(BatchRepository::class)->store(Bus::batch([])->name('kept'));

        $migration = require database_path('migrations/2026_09_16_000001_create_job_batches_table.php');
        $migration->up();

        $this->assertSame('kept', Bus::findBatch($batch->id)?->name);
    }
}

class JobBatchesTableTestJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public static int $handled = 0;

    public function handle(): void
    {
        self::$handled++;
    }
}
