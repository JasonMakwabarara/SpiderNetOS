<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tenant phone number → agent mapping
        Schema::create('voice_numbers', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->string('phone_number', 20)->index();
            $table->string('provider', 20)->default('twilio'); // twilio, vonage
            $table->string('provider_sid', 100)->nullable(); // Twilio SID
            $table->string('agent_id', 100)->default('voice_receptionist');
            $table->jsonb('config')->nullable(); // STT/TTS provider, voice settings
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'phone_number']);
        });

        // Call history and transcripts
        Schema::create('voice_calls', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->string('call_sid', 100)->unique(); // Twilio CallSid
            $table->string('phone_number', 20)->index();
            $table->string('from_number', 20);
            $table->string('direction', 10)->default('inbound'); // inbound, outbound
            $table->string('status', 20)->default('initiated'); // initiated, ringing, in-progress, completed, failed, busy, no-answer
            $table->integer('duration_seconds')->nullable();
            $table->jsonb('transcript')->nullable(); // [{speaker, text, timestamp}, ...]
            $table->text('summary')->nullable();
            $table->jsonb('actions_taken')->nullable(); // tools executed
            $table->jsonb('metadata')->nullable(); // recording_url, cost breakdown
            $table->decimal('cost_estimate', 10, 4)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'started_at']);
        });

        // Post-call processing queue
        Schema::create('voice_call_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voice_call_id')->constrained('voice_calls')->onDelete('cascade');
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->text('summary');
            $table->jsonb('key_points')->nullable();
            $table->jsonb('follow_up_tasks')->nullable();
            $table->string('sentiment', 20)->nullable(); // positive, neutral, negative
            $table->boolean('email_sent')->default(false);
            $table->timestamp('processed_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voice_call_summaries');
        Schema::dropIfExists('voice_calls');
        Schema::dropIfExists('voice_numbers');
    }
};
