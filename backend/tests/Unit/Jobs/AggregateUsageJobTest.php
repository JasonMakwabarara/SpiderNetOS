<?php

namespace Tests\Unit\Jobs;

use App\Jobs\AggregateUsageJob;
use App\Services\FeatureFlag;
use App\Services\UsageAggregateShadow;
use Tests\TestCase;

/**
 * AggregateUsageJobTest
 *
 * Static analysis + flag-gating tests for the AggregateUsageJob.
 * Full DB integration tests run in CI against Postgres.
 */
class AggregateUsageJobTest extends TestCase
{
    // -----------------------------------------------------------------------
    // T2/T3: Source-level validation
    // -----------------------------------------------------------------------

    public function test_no_mysql_json_functions_in_source(): void
    {
        $src = file_get_contents((new \ReflectionClass(AggregateUsageJob::class))->getFileName());

        $this->assertStringNotContainsString('JSON_EXTRACT', $src, 'MySQL JSON_EXTRACT must not appear');
        $this->assertStringNotContainsString('JSON_UNQUOTE', $src, 'MySQL JSON_UNQUOTE must not appear');
        $this->assertStringContainsString("payload->>", $src, 'Postgres jsonb operator must be used');
    }

    public function test_upsert_writes_canonical_columns_not_legacy(): void
    {
        $src = file_get_contents((new \ReflectionClass(AggregateUsageJob::class))->getFileName());

        $this->assertStringContainsString("'total_calls'", $src, 'total_calls must be in upsert values');
        $this->assertStringContainsString("'total_tokens'", $src, 'total_tokens must be in upsert values');
        $this->assertStringContainsString("'calculated_at'", $src, 'calculated_at must be in upsert values');
        $this->assertStringContainsString("'cost_ceiling'", $src, 'cost_ceiling must be in upsert values');
        $this->assertStringNotContainsString("'request_count'", $src, 'request_count must NOT appear');
        $this->assertStringNotContainsString("'updated_at'", $src, 'updated_at must NOT appear in upsert');
    }

    public function test_emits_persist_event_with_v2_schema(): void
    {
        $src = file_get_contents((new \ReflectionClass(AggregateUsageJob::class))->getFileName());

        $this->assertStringContainsString("'usage.aggregate.persisted'", $src, 'Must emit usage.aggregate.persisted event');
        $this->assertStringContainsString("'schema_version' => '2.0.0'", $src, 'Event must declare schema_version 2.0.0');
    }

    public function test_shadow_diff_wiring_present(): void
    {
        $src = file_get_contents((new \ReflectionClass(AggregateUsageJob::class))->getFileName());

        $this->assertStringContainsString('UsageAggregateShadow', $src, 'Must reference UsageAggregateShadow for diff logging');
        $this->assertStringContainsString('->diff(', $src, 'Must call diff() on shadow service');
    }

    public function test_feature_flag_gates_execution(): void
    {
        $src = file_get_contents((new \ReflectionClass(AggregateUsageJob::class))->getFileName());

        $this->assertStringContainsString("FeatureFlag::on('atlas.usage_aggregates_v2')", $src, 'Must gate on v2 flag');
        $this->assertStringContainsString("atlas.usage_aggregates_v2.shadow", $src, 'Must check shadow flag');
        $this->assertStringContainsString("atlas.usage_aggregates_v2.cutover", $src, 'Must check cutover flag');
    }
}
