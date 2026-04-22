<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D — Voice Quotas
 *
 * Per-tenant monthly usage caps for voice, SMS, and outbound calls.
 * Enforced by VoiceController::checkQuota() before initiating calls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_quotas', function (Blueprint $table) {
            $table->uuid('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            // Monthly caps (0 = unlimited)
            $table->unsignedInteger('monthly_minutes_cap')->default(0);
            $table->unsignedInteger('monthly_minutes_used')->default(0);
            $table->unsignedInteger('outbound_cap')->default(0);
            $table->unsignedInteger('outbound_used')->default(0);
            $table->unsignedInteger('sms_cap')->default(0);
            $table->unsignedInteger('sms_used')->default(0);

            // Reset date (first of each month)
            $table->date('reset_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_quotas');
    }
};
