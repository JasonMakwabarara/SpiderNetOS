<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas voice personas (plan D7 §7): a seeded projection of
 * inference/voice_personas.yaml (`php artisan voice:sync-personas`), one row
 * per candidate voice. ElevenLabs is primary, Fish Audio and Intron are the
 * alternatives, Piper is the dev floor; Azure rows are only seeded with
 * --include-azure (Jason rejected the Azure voices, 2026-09-16).
 *
 * Resolution: users.voice_persona_slug → tenants.settings.voice.default_persona
 * → config('voice.default_persona') → first active persona. A cloned voice
 * (consent.type = cloned) is never spoken without complete consent
 * {subject_name, consent_doc_url, consented_at}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_personas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 96)->unique();
            $table->string('display_name');

            // elevenlabs | fishaudio | intron | piper | azure (dormant)
            $table->string('provider', 24);
            // ElevenLabs voice id, Fish Audio reference_id, Intron language/accent/gender,
            // Piper model; null for a placeholder or a Voice Design candidate not yet promoted.
            $table->string('provider_voice_id', 190)->nullable();

            $table->string('language', 16)->nullable();
            $table->string('accent', 96)->nullable();
            $table->string('gender', 24)->nullable();
            $table->string('style', 48)->nullable();
            $table->decimal('style_degree', 4, 2)->nullable();
            // Kept as strings: Azure prosody takes "+5%" / "-2st", Piper takes a speed multiplier.
            $table->string('rate', 16)->nullable();
            $table->string('pitch', 16)->nullable();
            $table->string('output_format', 64)->nullable();

            $table->string('preview_url', 500)->nullable();
            // null = unverified; re-check vendor pricing before setting it.
            $table->decimal('cost_per_1k_chars', 10, 4)->nullable();

            $table->jsonb('tags')->default('[]');
            // atlas | board:<seat> | character:<slug>
            $table->jsonb('recommended_for')->default('[]');
            // {type: stock|licensed|cloned, subject_name?, consent_doc_url?, consented_at?}
            $table->jsonb('consent')->default('{}');
            // design | null, plus provider extras (fish_model, intron {voice_language, voice_accent, voice_gender}).
            $table->string('kind', 24)->nullable();
            $table->jsonb('meta')->default('{}');

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('disabled_by_default')->default(false);

            $table->timestamps();

            $table->index(['provider', 'is_active']);
        });

        if (! Schema::hasColumn('users', 'voice_persona_slug')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('voice_persona_slug', 96)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'voice_persona_slug')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('voice_persona_slug');
            });
        }

        Schema::dropIfExists('voice_personas');
    }
};
