<?php

namespace Tests\Feature\Usage;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SchemaContractTest
 *
 * Verifies that usage_daily_aggregates and Atlas copy tables match the
 * checked-in JSON contracts in tests/Contracts/*.json.
 *
 * This test FAILS CI if any column drift occurs — acting as a permanent
 * regression guard.
 */
class SchemaContractTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // usage_daily_aggregates
    // -----------------------------------------------------------------------

    public function test_usage_daily_aggregates_schema_matches_contract(): void
    {
        $contract = $this->loadContract('usage_daily_aggregates.json');
        $table = $contract['table'];

        $this->assertTrue(Schema::hasTable($table), "Table {$table} must exist");

        foreach ($contract['columns'] as $column => $spec) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "Column {$table}.{$column} must exist"
            );
        }

        // Absent columns
        foreach ($contract['absent_columns'] ?? [] as $absent) {
            $this->assertFalse(
                Schema::hasColumn($table, $absent),
                "Column {$table}.{$absent} must NOT exist (was removed in v2)"
            );
        }
    }

    public function test_calculated_at_is_not_null_in_all_rows(): void
    {
        // After migration and backfill, no row should have NULL calculated_at
        $nullCount = DB::table('usage_daily_aggregates')
            ->whereNull('calculated_at')
            ->count();

        $this->assertSame(0, $nullCount, 'calculated_at must be populated in all rows');
    }

    public function test_request_count_column_absent(): void
    {
        $this->assertFalse(
            Schema::hasColumn('usage_daily_aggregates', 'request_count'),
            'request_count column must not exist after v2 cutover'
        );
    }

    // -----------------------------------------------------------------------
    // Atlas copy tables
    // -----------------------------------------------------------------------

    public function test_atlas_copy_tables_schema_matches_contract(): void
    {
        $contract = $this->loadContract('atlas_copy_tables.json');

        foreach ($contract['tables'] as $table => $spec) {
            $this->assertTrue(Schema::hasTable($table), "Atlas table {$table} must exist");

            foreach ($spec['columns'] as $column => $colSpec) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "Column {$table}.{$column} must exist"
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // STE projection tables (plan §12)
    // -----------------------------------------------------------------------

    public function test_ste_tables_schema_matches_contract(): void
    {
        // STE migration uses Postgres-only DDL (jsonb, md5(tags::text) indexes).
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('STE schema contract requires Postgres.');
        }

        $contract = $this->loadContract('ste_tables.json');

        foreach ($contract['tables'] as $table => $spec) {
            $this->assertTrue(Schema::hasTable($table), "STE table {$table} must exist");

            foreach ($spec['columns'] as $column => $colSpec) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "Column {$table}.{$column} must exist"
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // Shadow diffs gate
    // -----------------------------------------------------------------------

    public function test_shadow_diffs_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('usage_shadow_diffs'));
    }

    public function test_cutover_gate_query_returns_zero_on_fresh_db(): void
    {
        // On a fresh migration there should be no open diffs
        $openDiffs = DB::table('usage_shadow_diffs')
            ->where('detected_at', '>=', now()->subHours(24))
            ->whereNull('resolved_at')
            ->count();

        $this->assertSame(0, $openDiffs, 'Cutover gate must return 0 on a clean environment');
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    private function loadContract(string $filename): array
    {
        $path = base_path("tests/Contracts/{$filename}");
        $this->assertFileExists($path, "Contract file {$filename} must exist");

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
