<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->nullable()->unique();
            $table->string('plan', 32)->default('free');
            $table->jsonb('settings')->nullable();
            $table->jsonb('limits')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['status', 'plan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
