<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web-push subscriptions (browser PushSubscription endpoints) + per-user
 * notification preferences for the cockpit PWA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('tenant_id')->nullable()->index();
            $table->text('endpoint');
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->timestamps();

            $table->unique(['user_id', 'endpoint']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('event_type', 32);  // approval_pending | budget_alert
            $table->string('channel', 16);      // push | in_app
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'event_type', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('push_subscriptions');
    }
};
