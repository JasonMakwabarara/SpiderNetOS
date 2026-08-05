<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Stage 3 — Accounting exports (spend domain).
|
| accounting_exports     — one row per generated export file (QuickBooks /
|                          Xero / generic journal CSV over a posted_at range).
| spend_export_schedules — recurring export configuration; the daily
|                          RunScheduledSpendExportsJob generates the previous
|                          full week/month when a schedule comes due.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('export_type', 30); // quickbooks_csv|xero_csv|generic_csv
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 16)->default('pending'); // pending|generated|failed
            $table->string('file_path')->nullable();
            $table->integer('row_count')->nullable();
            $table->uuid('requested_by');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('spend_export_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('export_type', 30);
            $table->string('scope', 20)->default('journal');
            $table->string('frequency', 10); // weekly|monthly
            $table->string('delivery', 16)->default('notification'); // notification|webhook
            $table->json('destination')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spend_export_schedules');
        Schema::dropIfExists('accounting_exports');
    }
};
