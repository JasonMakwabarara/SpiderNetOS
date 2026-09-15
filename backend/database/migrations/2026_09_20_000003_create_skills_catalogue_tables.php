<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skills catalogue (plan D5): a global, seeded projection of
 * packages/skills/<slug>/{card.yaml, prompts/task.md} so the runtime and the
 * cockpit card read one row instead of the filesystem. Re-seeded
 * idempotently by `skills:seed` (SeedSystemTemplates pattern). Per-tenant
 * state lives in tenant_skills (runtime migration); brain files cover the
 * tenant side, so there is no tenant_brain_files table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            // Card id, kebab-case, e.g. cold-email-drafting.
            $table->string('slug', 64)->primary();
            $table->string('version', 16)->default('1.0.0');
            $table->string('name');

            // sales | deals | marketing | operations | intelligence | customer | back_office | people | founder
            $table->string('pillar', 24);
            // business_systems.function the pillar maps to on the business map.
            $table->string('map_function', 24)->nullable();
            // Node label on the map, e.g. "Sales › Outreach writing".
            $table->string('map_node')->nullable();
            // Identity that runs it (identities.yaml key), e.g. growth, richard, recruiter_bot.
            $table->string('runs_on', 64)->nullable();
            // Core character the identity reports to: atlas | hannah | forge | sentinel | prism | nexus
            $table->string('core_agent', 16)->nullable();
            // Feature pack that provides/entitles it (null = platform skill).
            $table->string('pack_id', 64)->nullable();

            // The full card.yaml content model (validated against
            // packages/skills/_schema/skill-card.schema.json).
            $table->jsonb('card')->default('{}');
            // prompts/task.md as shipped; SkillPromptBuilder layers brain + character on top.
            $table->longText('prompt_md')->nullable();
            // sha256 of card.yaml + prompt, so skills:seed can skip unchanged cards.
            $table->char('card_hash', 64)->nullable();

            $table->timestamps();

            $table->index('pillar');
            $table->index('pack_id');
            $table->index('runs_on');
        });

        Schema::create('skill_relations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('from_slug', 64);
            $table->foreign('from_slug')->references('slug')->on('skills')->onDelete('cascade');

            // breaks_into | builds_on | hands_off_to | replaces
            $table->string('relation', 16);
            // Another card...
            $table->string('to_slug', 64)->nullable();
            // ...or an external ref: richard, hannah_ai, brain:brand/voice.md#tone,
            // principle:hook-problem-solution, role:SDR
            $table->string('to_ref', 190)->nullable();
            // Relation-specific detail, e.g. replaces {what, cost, kind}, hands_off_to {when}.
            $table->jsonb('meta')->default('{}');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['from_slug', 'relation']);
            $table->index('to_slug');
            $table->unique(['from_slug', 'relation', 'to_slug', 'to_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_relations');
        Schema::dropIfExists('skills');
    }
};
