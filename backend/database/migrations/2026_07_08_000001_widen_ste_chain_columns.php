<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Widens ste_transitions.chain / ste_event_mapping.chain from varchar(32) to
 * varchar(64). Pack-namespaced chains ("pack.{pack_id}.{chain_id}") routinely
 * exceed 32 chars — e.g. "pack.real-estate-crm.lead_lifecycle" is 35 chars —
 * so any attempt to seed pack STE mappings would fail on insert. Purely
 * additive (widening a varchar never truncates existing data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ste_transitions', function ($table) {
            $table->string('chain', 64)->change();
        });

        Schema::table('ste_event_mapping', function ($table) {
            $table->string('chain', 64)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ste_transitions', function ($table) {
            $table->string('chain', 32)->change();
        });

        Schema::table('ste_event_mapping', function ($table) {
            $table->string('chain', 32)->change();
        });
    }
};
