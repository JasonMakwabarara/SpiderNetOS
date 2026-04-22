<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * State Transition Engine — projection tables (Phase 1).
 *
 * These are pure projections over event_log. The projector runs synchronously
 * in the same transaction as event append via config/projections.php.
 *
 * Idempotency guarantees:
 *   - ste_transitions: UNIQUE (chain, from_state, to_state, tags, tenant_id)
 *                       + ON CONFLICT DO UPDATE count = count + 1
 *   - ste_*_states: last_sequence_num cursor prevents state regression
 *   - ste_unmapped_events: one row per distinct event type (bounded cardinality)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ste_transitions — the materialised Markov matrix rows
        Schema::create('ste_transitions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('chain', 32);              // 'session_lifecycle' | 'tenant_lifecycle'
            $table->string('from_state', 64);
            $table->string('to_state', 64);
            $table->jsonb('tags')->default(DB::raw("'{}'::jsonb"));
            $table->uuid('tenant_id')->nullable();    // NULL = cross-tenant aggregate
            $table->bigInteger('count')->default(0);
            $table->timestampTz('last_seen_at')->default(DB::raw('now()'));

            $table->index(['chain', 'from_state']);
            $table->index(['tenant_id', 'chain']);
        });

        // Postgres requires md5(tags::text) to get a unique index on jsonb
        // (jsonb is not directly comparable for uniqueness in an index of this size).
        DB::statement("
            CREATE UNIQUE INDEX ste_transitions_dedup_idx
              ON ste_transitions (chain, from_state, to_state, md5(tags::text), COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'))
        ");

        // ste_session_states
        Schema::create('ste_session_states', function (Blueprint $table) {
            $table->uuid('session_id')->primary();
            $table->uuid('tenant_id');
            $table->string('current_state', 64);
            $table->string('last_state', 64)->nullable();
            $table->timestampTz('entered_at')->default(DB::raw('now()'));
            $table->timestampTz('updated_at')->default(DB::raw('now()'));
            $table->bigInteger('last_sequence_num');

            $table->index(['tenant_id', 'current_state']);
        });

        // ste_tenant_states
        Schema::create('ste_tenant_states', function (Blueprint $table) {
            $table->uuid('tenant_id')->primary();
            $table->string('current_state', 64);
            $table->string('last_state', 64)->nullable();
            $table->timestampTz('entered_at')->default(DB::raw('now()'));
            $table->timestampTz('updated_at')->default(DB::raw('now()'));
            $table->bigInteger('last_sequence_num');
        });

        // ste_event_mapping — authored once, editable by super_admin
        Schema::create('ste_event_mapping', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_type', 96);
            $table->string('chain', 32);
            $table->string('from_state', 64)->nullable();  // NULL = any prior state
            $table->string('to_state', 64);
            $table->jsonb('extract_tags')->default(DB::raw("'{}'::jsonb"));
            $table->boolean('enabled')->default(true);
            $table->timestampTz('created_at')->default(DB::raw('now()'));
        });

        DB::statement("
            CREATE UNIQUE INDEX ste_event_mapping_dedup_idx
              ON ste_event_mapping (event_type, chain, COALESCE(from_state, ''), to_state)
        ");

        // ste_unmapped_events — observability for missing mappings
        Schema::create('ste_unmapped_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_type', 96)->unique();
            $table->uuid('sample_event_id');
            $table->uuid('tenant_id');
            $table->timestampTz('first_seen_at')->default(DB::raw('now()'));
            $table->bigInteger('count')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ste_unmapped_events');
        Schema::dropIfExists('ste_event_mapping');
        Schema::dropIfExists('ste_tenant_states');
        Schema::dropIfExists('ste_session_states');
        Schema::dropIfExists('ste_transitions');
    }
};
