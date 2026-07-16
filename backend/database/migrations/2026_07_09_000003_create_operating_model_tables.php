<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Priestley Five A's operating rhythm — Alignment (this table + 3-1-90
 * targets), Awareness (awareness_items), Accountability (scoreboard reads
 * pack targets: at query time, no table needed), Activity (weekly_rhythms),
 * Assets (business_assets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_alignment_profiles', function (Blueprint $table) {
            $table->uuid('tenant_id')->primary();
            $table->text('origin_story')->nullable();
            $table->text('mission')->nullable();
            $table->text('vision')->nullable();
            $table->jsonb('values')->default('[]');
            // Each target: {metric, goal, owner_role}
            $table->jsonb('three_year_targets')->default('[]');
            $table->jsonb('one_year_targets')->default('[]');
            $table->jsonb('ninety_day_targets')->default('[]');
            $table->timestampTz('current_cycle_started_at')->nullable();
            $table->timestamps();
        });

        Schema::create('awareness_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('source', 16); // anomaly | agent | human | checkin
            $table->string('title');
            $table->text('detail')->nullable();
            $table->string('severity', 16)->default('info'); // info | warning | critical
            $table->string('status', 16)->default('open'); // open | acknowledged | resolved
            $table->string('raised_by', 64)->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('weekly_rhythms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->date('week_start');
            // [{text, owner_role, done}]
            $table->jsonb('priorities')->default('[]');
            // {summary, done_count, total_count, locked_antlers}
            $table->jsonb('checkin')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'week_start']);
        });

        Schema::create('business_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('type', 24); // sales_script | message_template | flow | landing_page | document | policy
            $table->string('name');
            $table->string('ref_type')->nullable(); // polymorphic: FQCN of the source model
            $table->uuid('ref_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('quarter', 8)->nullable();
            $table->string('status', 16)->default('active'); // active | retired
            $table->string('created_by', 64)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'quarter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_assets');
        Schema::dropIfExists('weekly_rhythms');
        Schema::dropIfExists('awareness_items');
        Schema::dropIfExists('tenant_alignment_profiles');
    }
};
