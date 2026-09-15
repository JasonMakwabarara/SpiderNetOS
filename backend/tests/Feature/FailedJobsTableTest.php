<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * queue.failed is database-uuids on failed_jobs: without the table a job that
 * runs out of tries loses its failure record, and queue:failed / queue:retry
 * throw instead of listing anything.
 */
class FailedJobsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_job_is_recorded_listed_and_forgotten(): void
    {
        $failer = $this->app->make('queue.failer');
        $uuid = $this->logFailure($failer, 'boom');

        $this->assertSame([$uuid], array_column($failer->all(), 'id'));
        $this->assertStringContainsString('boom', $failer->find($uuid)->exception);
        $this->artisan('queue:failed')->expectsOutputToContain($uuid)->assertSuccessful();

        $this->assertTrue($failer->forget($uuid));
        $this->artisan('queue:failed')->expectsOutputToContain('No failed jobs found.')->assertSuccessful();
    }

    public function test_the_migration_is_a_no_op_where_the_table_already_exists(): void
    {
        $failer = $this->app->make('queue.failer');
        $uuid = $this->logFailure($failer, 'kept');

        $migration = require database_path('migrations/2026_09_15_000001_create_failed_jobs_table.php');
        $migration->up();

        $this->assertSame([$uuid], array_column($failer->all(), 'id'));
    }

    private function logFailure(FailedJobProviderInterface $failer, string $message): string
    {
        $uuid = (string) Str::uuid();
        $failer->log('redis', 'default', (string) json_encode(['uuid' => $uuid]), new RuntimeException($message));

        return $uuid;
    }
}
