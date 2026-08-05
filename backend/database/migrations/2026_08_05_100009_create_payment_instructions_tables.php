<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Stage 2 — Bill pay / AP (spend domain).
|
| payment_instructions    — the disbursement intent for a bill (or, later, a
|                           reimbursement). rail names which PaymentRail
|                           executes it; record_only is the V1 default (the
|                           sweep notifies, it never moves money).
| recurring_bill_templates — cadence templates that GenerateRecurringBillsJob
|                           turns into draft bills (or bill.recurring_due
|                           events when autocreate is off).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_instructions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('bill_id')->nullable()->index();
            $table->uuid('reimbursement_id')->nullable()->index();
            // record_only|dodo_payments|...
            $table->string('rail', 30)->default('record_only');
            // pending|scheduled|submitted|settled|failed|cancelled
            $table->string('status', 16)->default('pending')->index();
            $table->decimal('amount', 19, 4);
            $table->string('currency', 10)->default('USD');
            $table->date('scheduled_for')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('idempotency_key', 100);
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'idempotency_key']);
        });

        Schema::create('recurring_bill_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('vendor_id')->nullable();
            $table->string('name');
            $table->decimal('amount', 19, 4);
            $table->string('currency', 10)->default('USD');
            $table->uuid('category_id')->nullable();
            // weekly|monthly
            $table->string('cadence', 10);
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->date('next_run_date')->index();
            $table->string('last_period_key', 20)->nullable();
            $table->boolean('autocreate')->default(true);
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_bill_templates');
        Schema::dropIfExists('payment_instructions');
    }
};
