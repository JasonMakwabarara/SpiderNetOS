<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create Atlas copy / RL persistence layer
 *
 * Tables:
 *   atlas_prompts          — structured, machine-optimisable prompt library
 *   atlas_copy_variants    — copy candidates with Thompson-Sampling state
 *   atlas_copy_impressions — per-impression event stream for offline RL replay
 *
 * All three tables are read/written only when the feature flag
 * `atlas.usage_aggregates_v2` is enabled, so they can be migrated safely
 * on any environment without affecting production reads until the flag is on.
 */
return new class extends Migration
{
    public function up(): void
    {
        // -------------------------------------------------------------------
        // 1. atlas_prompts — evolving prompt library (§11.1, §4.3.11)
        // -------------------------------------------------------------------
        Schema::create('atlas_prompts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('surface', 32)->index();

            // Machine-readable prompt components (§4.3.11)
            // Keys: instruction_style, tone, value_emphasis,
            //       emotional_weight (float), brevity_bias (float), cta_style
            $table->jsonb('components');

            $table->text('template');

            // active | elite | retired
            $table->string('status', 16)->default('active')->index();

            // Generation counter — increments on each evolution cycle
            $table->integer('generation')->default(0);

            // Parent prompt (for lineage tracking after mutation / crossover)
            $table->uuid('parent_prompt_id')->nullable();

            // Aggregate performance (updated by the macro evolution job)
            $table->decimal('avg_ts', 6, 4)->default(0);
            $table->decimal('avg_ctr', 6, 4)->default(0);
            $table->decimal('avg_conversion', 6, 4)->default(0);
            $table->decimal('stability', 6, 4)->default(0);
            $table->integer('impressions')->default(0);

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['surface', 'status']);
        });

        // -------------------------------------------------------------------
        // 2. atlas_copy_variants — candidate ledger + bandit state (§11.1)
        // -------------------------------------------------------------------
        Schema::create('atlas_copy_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('prompt_id')->index();

            $table->string('surface', 32)->index();
            $table->text('text');

            // CopyUnit fields: future_state, value, action, emotional_hook,
            // plus derived attributes (length, tone_tags, style)
            $table->jsonb('features');

            // RT scorer output at generation time
            $table->decimal('predicted_ts', 6, 4);
            $table->decimal('trust_score', 6, 4);

            // active | retired | fallback
            $table->string('status', 16)->default('active')->index();

            // Bandit counters
            $table->integer('impressions')->default(0);
            $table->integer('clicks')->default(0);
            $table->integer('actions')->default(0);
            $table->integer('conversions')->default(0);
            $table->decimal('sum_reward', 10, 4)->default(0);
            $table->decimal('avg_reward', 6, 4)->default(0);

            // Thompson Sampling Beta distribution parameters
            // alpha = prior_alpha + successes
            // beta  = prior_beta  + failures
            $table->decimal('alpha', 10, 4)->default(1);
            $table->decimal('beta', 10, 4)->default(1);

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['surface', 'status']);
            $table->foreign('prompt_id')->references('id')->on('atlas_prompts');
        });

        // -------------------------------------------------------------------
        // 3. atlas_copy_impressions — per-event stream (§11.1)
        //    TTL target: 90 days (enforced by BackfillUsageAggregates cron
        //    or a separate retention job — not part of this migration).
        // -------------------------------------------------------------------
        Schema::create('atlas_copy_impressions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('variant_id')->index();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->nullable();
            $table->string('surface', 32)->index();
            $table->jsonb('context');

            $table->timestampTz('shown_at')->useCurrent()->index();
            $table->timestampTz('clicked_at')->nullable();
            $table->timestampTz('action_at')->nullable();
            $table->timestampTz('conversion_at')->nullable();
            $table->integer('dwell_ms')->nullable();

            // Blended reward (written by the reward-computation job)
            $table->decimal('reward', 6, 4)->nullable();

            $table->index(['surface', 'shown_at']);
            $table->foreign('variant_id')->references('id')->on('atlas_copy_variants');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('atlas_copy_impressions');
        Schema::dropIfExists('atlas_copy_variants');
        Schema::dropIfExists('atlas_prompts');
    }
};
