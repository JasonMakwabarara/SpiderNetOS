<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email threading + draft/approval bookkeeping on conversation_messages.
 * Until now the table only knew WhatsApp-shaped turns (body + provider id);
 * partner outreach needs subjects, RFC Message-IDs for reply matching, a
 * classification for inbound mail and a home for bot drafts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->string('subject')->nullable();
            $table->string('message_id_header')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->text('references_header')->nullable();
            $table->jsonb('headers')->default('{}');
            // reply | bounce | auto_reply | opt_out | decline | unmatched
            $table->string('classification', 24)->nullable();
            $table->uuid('approval_id')->nullable();
            $table->string('draft_action', 24)->nullable();
            $table->jsonb('draft_meta')->default('{}');
            $table->timestamp('sent_at')->nullable();
        });

        // Inbound dedupe key: the same RFC Message-ID is only ever stored once
        // per tenant, so re-polling a mailbox needs no cursor. Partial so the
        // many rows without a header (WhatsApp, drafts) never collide.
        DB::statement('
            CREATE UNIQUE INDEX conversation_messages_tenant_message_id_unique
              ON conversation_messages (tenant_id, message_id_header)
              WHERE message_id_header IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS conversation_messages_tenant_message_id_unique');

        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropColumn([
                'subject', 'message_id_header', 'in_reply_to', 'references_header', 'headers',
                'classification', 'approval_id', 'draft_action', 'draft_meta', 'sent_at',
            ]);
        });
    }
};
