<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner outreach (affiliate recruitment): one prospect row per lead plus
 * a generic webhook receipt ledger. Plain Blueprint calls and string jsonb
 * defaults so the Feature suite runs on sqlite as well as Postgres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_prospects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('lead_id')->unique();
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');

            // Identity on the platform the prospect was found on.
            $table->string('platform', 16)->default('other'); // tiktok|facebook|instagram|youtube|web|other
            $table->string('handle', 190)->nullable();
            $table->text('profile_url');
            $table->char('profile_url_hash', 64);
            $table->text('primary_content_url')->nullable();
            $table->jsonb('all_urls')->default('[]');
            $table->string('display_name')->nullable();
            $table->string('source', 32)->default('csv'); // csv|finder|manual
            $table->jsonb('source_meta')->default('{}');

            // Affonso linkage (filled by the webhook / API in later PRs).
            $table->string('affonso_shortlist_item_id', 64)->nullable();
            $table->string('affonso_affiliate_id', 64)->nullable();
            $table->string('affonso_tracking_id', 64)->nullable();
            $table->string('affiliate_status', 16)->nullable();

            // Lifecycle.
            $table->char('invite_token', 22)->unique();
            $table->string('status', 24)->default('new');
            $table->string('email_source', 16)->nullable(); // finder|operator|fetch
            $table->timestamp('email_verified_at')->nullable();
            $table->unsignedTinyInteger('sequence_step')->default(0);
            $table->timestamp('next_send_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('signed_up_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->string('bounce_reason')->nullable();
            $table->timestamp('needs_human_at')->nullable();
            $table->string('needs_human_reason')->nullable();
            $table->timestamp('bot_paused_at')->nullable();
            $table->timestamp('dm_draft_at')->nullable();
            $table->timestamp('dm_sent_at')->nullable();

            // Concurrency claims (conditional UPDATEs, see ProspectStateMachine).
            $table->timestamp('claimed_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->uuid('reply_claim_message_id')->nullable();
            $table->unsignedSmallInteger('bot_replies_today')->default(0);
            $table->date('bot_replies_day')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'profile_url_hash']);
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'next_send_at']);
            $table->index(['tenant_id', 'affonso_affiliate_id']);
        });

        Schema::create('webhook_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('provider', 32);
            $table->string('event_id', 128);
            $table->string('event_type', 64);
            $table->jsonb('payload')->default('{}');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_receipts');
        Schema::dropIfExists('partner_prospects');
    }
};
