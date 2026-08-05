<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Stage 3 — Accounting automation (spend domain).
|
| spend_posting_rules — one row per tenant configuring how approved spend is
|                       posted to the general ledger. mode 'auto' posts on
|                       approval/payment; 'draft' records the journal for an
|                       explicit admin-triggered post. Credit account CODES
|                       (not uuids — the column is 20 chars, a uuid is 36)
|                       are resolved against financial_accounts.account_number
|                       first, then chart_of_accounts.code (mirrored into a
|                       financial account on first use — see GlPostingService).
| gl_postings         — one row per posted source document. The unique
|                       (tenant_id, source_type, source_id) key is the
|                       exactly-once guard: the row is inserted with
|                       insert-or-ignore and a 'posted' row is never redone,
|                       so job retries cannot double-post.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spend_posting_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->string('mode', 10)->default('draft'); // auto|draft
            $table->string('expense_credit_account_code', 20)->nullable();
            $table->string('bill_credit_account_code', 20)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('gl_postings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('source_type', 20); // expense_report|bill
            $table->uuid('source_id');
            $table->string('transaction_number', 50)->nullable();
            $table->string('status', 12)->default('draft')->index(); // draft|posted|failed|skipped
            $table->decimal('amount', 19, 4);
            $table->string('currency', 10)->default('USD');
            $table->json('lines');
            $table->text('error')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            // Exactly-once guard for the posting pipeline.
            $table->unique(['tenant_id', 'source_type', 'source_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_postings');
        Schema::dropIfExists('spend_posting_rules');
    }
};
