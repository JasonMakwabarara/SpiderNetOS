<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-use email sign-in links (share_links house pattern: opaque
        // Str::random token returned once, sha256 hash stored, no FKs).
        Schema::create('magic_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token_hash', 64)->unique();
            // Bound at mint time so verify can never resolve to a different
            // user than the one the link was issued for (emails are only
            // unique per-tenant).
            $table->uuid('user_id')->index();
            $table->string('email', 190)->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_links');
    }
};
