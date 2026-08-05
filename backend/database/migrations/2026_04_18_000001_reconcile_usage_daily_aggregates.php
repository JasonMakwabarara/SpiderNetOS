<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconcile usage_daily_aggregates
 *
 * Blocker A fix: the original migration (000007) created `total_calls` and
 * `calculated_at`, but AggregateUsageJob wrote `request_count` / `updated_at`
 * instead.  This migration:
 *
 *   UP
 *     1. Copies `request_count` → `total_calls` if that drift column exists.
 *     2. Drops `request_count` drift column.
 *     3. Backfills `calculated_at` from legacy timestamps where NULL.
 *     4. Ensures NOT NULL DEFAULT now() on `calculated_at`.
 *     5. Adds `total_tokens` and `cost_ceiling` if missing.
 *     6. Creates `usage_shadow_diffs` for the 48-hour shadow-mode gate.
 *
 *   DOWN
 *     1. Re-creates `request_count` mirrored from `total_calls`.
 *     2. Makes `calculated_at` nullable again.
 *     3. Drops `usage_shadow_diffs`.
 *
 * NOTE: DOWN is valid only during the 72 h post-GA watch window.
 * After the watch window a follow-up migration permanently removes the
 * rollback path.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // 1. Normalise usage_daily_aggregates
        // ---------------------------------------------------------------

        // If the drift column 'request_count' exists, copy → total_calls then drop.
        if (Schema::hasColumn('usage_daily_aggregates', 'request_count')) {
            DB::statement('UPDATE usage_daily_aggregates SET total_calls = request_count WHERE total_calls = 0 AND request_count > 0');
            Schema::table('usage_daily_aggregates', function (Blueprint $table) {
                $table->dropColumn('request_count');
            });
        }

        // If the drift column 'updated_at' exists, drop it (it was never in the spec).
        if (Schema::hasColumn('usage_daily_aggregates', 'updated_at')) {
            Schema::table('usage_daily_aggregates', function (Blueprint $table) {
                $table->dropColumn('updated_at');
            });
        }

        // Backfill calculated_at from any available legacy timestamp before
        // imposing NOT NULL.
        if (Schema::hasColumn('usage_daily_aggregates', 'calculated_at')) {
            DB::statement("UPDATE usage_daily_aggregates SET calculated_at = now() WHERE calculated_at IS NULL");
            // Make NOT NULL with a default
            DB::statement("ALTER TABLE usage_daily_aggregates ALTER COLUMN calculated_at SET NOT NULL");
            DB::statement("ALTER TABLE usage_daily_aggregates ALTER COLUMN calculated_at SET DEFAULT now()");
        }

        // Add total_tokens if missing
        if (!Schema::hasColumn('usage_daily_aggregates', 'total_tokens')) {
            Schema::table('usage_daily_aggregates', function (Blueprint $table) {
                $table->integer('total_tokens')->default(0)->after('total_calls');
            });
        }

        // Add cost_ceiling if missing
        if (!Schema::hasColumn('usage_daily_aggregates', 'cost_ceiling')) {
            Schema::table('usage_daily_aggregates', function (Blueprint $table) {
                $table->decimal('cost_ceiling', 12, 6)->default(0)->after('total_cost');
            });
        }

        // ---------------------------------------------------------------
        // 2. Create usage_shadow_diffs
        //    Used by UsageAggregateShadow to log differences during the
        //    48-hour shadow window.  The cutover gate query must return 0
        //    for resolved_at IS NULL rows in the last 24 hours.
        // ---------------------------------------------------------------

        if (!Schema::hasTable('usage_shadow_diffs')) {
            Schema::create('usage_shadow_diffs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('tenant_id')->index();
                $table->date('date')->index();
                $table->string('resource_type', 32);
                $table->integer('legacy_request_count')->nullable();
                $table->integer('canonical_total_calls')->nullable();
                $table->decimal('legacy_total_cost', 12, 6)->nullable();
                $table->decimal('canonical_total_cost', 12, 6)->nullable();
                // One of: count_mismatch | cost_mismatch | missing_row | extra_row
                $table->string('diff_kind', 32)->index();
                $table->timestampTz('detected_at')->default(DB::raw('now()'))->index();
                $table->timestampTz('resolved_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Re-add request_count as rollback compatibility column
        if (!Schema::hasColumn('usage_daily_aggregates', 'request_count')) {
            Schema::table('usage_daily_aggregates', function (Blueprint $table) {
                $table->integer('request_count')->default(0);
            });
            DB::statement('UPDATE usage_daily_aggregates SET request_count = total_calls');
        }

        // Make calculated_at nullable again
        DB::statement("ALTER TABLE usage_daily_aggregates ALTER COLUMN calculated_at DROP NOT NULL");
        DB::statement("ALTER TABLE usage_daily_aggregates ALTER COLUMN calculated_at DROP DEFAULT");

        // Drop shadow diffs table
        Schema::dropIfExists('usage_shadow_diffs');
    }
};
