<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two small founder-loop tables (plan D8 #8 and #4):
 *
 * approval_reviews — the cockpit posts how an approval was actually reviewed
 * (dwell time, whether the diff was expanded, whether the body was edited,
 * the decision) so the promotion gate counts real reviews, not clicks.
 *
 * notification_bundle_items — bundle-tier notifications parked by
 * NotificationBundler until the user's bundle_time, then sent as one
 * summary ("4 drafts, 1 question — about 6 minutes").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('approval_id')->index();
            $table->uuid('user_id')->nullable();

            $table->unsignedInteger('dwell_ms')->default(0);
            $table->boolean('diff_expanded')->default(false);
            $table->boolean('edited')->default(false);
            // approved | rejected | edited | deferred | viewed
            $table->string('decision', 16)->nullable();
            $table->jsonb('meta')->default('{}');

            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('notification_bundle_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->index();

            $table->string('event_type', 48);
            $table->jsonb('payload')->default('{}');
            // pending | sent | dropped
            $table->string('status', 16)->default('pending');
            // Id shared by every item sent in the same summary.
            $table->uuid('bundle_id')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_bundle_items');
        Schema::dropIfExists('approval_reviews');
    }
};
