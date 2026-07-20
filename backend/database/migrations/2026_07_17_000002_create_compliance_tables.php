<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Privacy/compliance machinery: a consent audit trail for messaging channels
 * (also a prerequisite for W5 outbound gating) and DSAR (data-subject access /
 * erasure) request tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('subject', 190);            // phone number or email
            $table->string('channel', 16);             // imessage | sms | whatsapp | email
            $table->string('status', 16);              // granted | revoked | stopped
            $table->string('source', 48)->nullable();  // inbound_stop | lead_form | api | import
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->index(['tenant_id', 'subject', 'channel']);
        });

        Schema::create('dsar_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('type', 16);                // export | erasure
            $table->string('subject_type', 16);        // user | lead | email
            $table->uuid('subject_id')->nullable();
            $table->string('subject_email', 190)->nullable();
            $table->string('status', 16)->default('pending'); // pending processing completed failed
            $table->uuid('requested_by')->nullable();
            $table->string('artifact_path', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dsar_requests');
        Schema::dropIfExists('consent_records');
    }
};
