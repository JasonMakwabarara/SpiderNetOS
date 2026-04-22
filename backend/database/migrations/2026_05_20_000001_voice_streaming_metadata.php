<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C — Streaming Metadata
 *
 * Adds streaming-specific columns to voice_calls.
 * Additive only; safe to roll back via down().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_calls', function (Blueprint $table) {
            // Identifies the Media Streams WebSocket session for this call
            $table->string('stream_session_id', 64)->nullable()->after('ended_at');

            // Time in ms from call ring to first TTS audio chunk sent to Twilio
            $table->unsignedInteger('first_audio_ms')->nullable()->after('stream_session_id');

            // Number of barge-in events (caller interrupted the agent)
            $table->unsignedSmallInteger('barge_ins')->default(0)->after('first_audio_ms');

            // STT / TTS provider used in this call (may differ from config default)
            $table->string('stt_provider', 32)->nullable()->after('barge_ins');
            $table->string('tts_provider', 32)->nullable()->after('stt_provider');
        });
    }

    public function down(): void
    {
        Schema::table('voice_calls', function (Blueprint $table) {
            $table->dropColumn([
                'stream_session_id',
                'first_audio_ms',
                'barge_ins',
                'stt_provider',
                'tts_provider',
            ]);
        });
    }
};
