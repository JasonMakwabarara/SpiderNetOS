<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business map (plan D5 / D6-C): a systemization process can name the skill
 * card that does it, so the map puts the process on that skill's node and
 * "Automate" can hand it to the skill's agent (owner_type=agent). Nullable
 * and not a foreign key: skills are a seeded global catalogue that can be
 * re-projected, and a process must survive a card being renamed.
 * Additive only, so it is SQLite-safe (no table rebuild on up).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('business_processes', 'skill_slug')) {
            return;
        }

        Schema::table('business_processes', function (Blueprint $table) {
            $table->string('skill_slug', 64)->nullable()->after('system_id');
            $table->index(['tenant_id', 'skill_slug'], 'business_processes_tenant_skill_slug_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('business_processes', 'skill_slug')) {
            return;
        }

        Schema::table('business_processes', function (Blueprint $table) {
            $table->dropIndex('business_processes_tenant_skill_slug_index');
        });
        Schema::table('business_processes', function (Blueprint $table) {
            $table->dropColumn('skill_slug');
        });
    }
};
