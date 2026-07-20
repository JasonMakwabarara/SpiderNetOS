<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real TOTP second factor for step-up: an encrypted shared secret on the user
 * plus single-use recovery codes. Replaces the placeholder MFA check in
 * AuthController::stepUp().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('totp_secret')->nullable();          // encrypted (App\Models\User cast)
            $table->timestampTz('totp_confirmed_at')->nullable();
        });

        Schema::create('mfa_recovery_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('code_hash', 255);
            $table->timestampTz('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['totp_secret', 'totp_confirmed_at']);
        });
    }
};
