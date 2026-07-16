<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('pack_id', 64)->default('sales-crm');
            $table->string('channel', 16); // email | whatsapp
            $table->string('key', 64); // e.g. nurture.step1.opener
            $table->string('subject')->nullable(); // email only
            // Merge-field syntax: {{lead.first_name}} {{business.name}} {{brand.*}} {{script.*}}
            $table->text('body');
            $table->string('whatsapp_template_sid', 64)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'channel', 'key']);
        });

        // Dedicated per-tenant WhatsApp (and future SMS) numbers — separate
        // from voice_numbers, which is call-routing specific (agent_id,
        // STT/TTS config) and shouldn't be overloaded for messaging.
        Schema::create('messaging_numbers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('channel', 16); // whatsapp | sms
            $table->string('phone_number', 32)->unique();
            $table->string('provider', 20)->default('twilio');
            $table->string('provider_sid', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('lead_id')->index();
            $table->string('channel', 16);
            $table->string('external_id', 128)->nullable(); // provider thread/phone-pair ref
            $table->string('status', 16)->default('open'); // open | pending | closed
            $table->string('assigned_to', 64)->nullable(); // agent slug or user id
            $table->timestampTz('last_message_at')->nullable();
            $table->timestamps();

            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->index(['tenant_id', 'channel', 'status']);
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('conversation_id')->index();
            $table->string('direction', 8); // in | out
            $table->text('body');
            $table->string('template_key', 64)->nullable();
            $table->string('provider_message_id', 128)->nullable();
            $table->string('status', 16)->default('queued'); // queued sent delivered read failed
            $table->text('error')->nullable();
            $table->string('sent_by', 64)->nullable(); // agent slug or user id
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
        });

        Schema::create('sequence_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('lead_id')->index();
            $table->string('sequence_key', 64)->default('nurture-sequence');
            $table->unsignedInteger('current_step')->default(0);
            $table->timestampTz('next_run_at')->nullable();
            $table->string('status', 16)->default('active'); // active paused completed cancelled
            $table->jsonb('context')->default('{}');
            $table->timestamps();

            $table->foreign('lead_id')->references('id')->on('leads')->cascadeOnDelete();
            $table->index(['status', 'next_run_at']);
            $table->unique(['lead_id', 'sequence_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequence_enrollments');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('messaging_numbers');
        Schema::dropIfExists('message_templates');
    }
};
