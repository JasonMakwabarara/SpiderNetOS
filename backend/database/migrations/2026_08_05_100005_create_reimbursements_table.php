<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Reimbursements — record-only payout ledger for approved expense reports.
| Money movement happens outside the system; mark-paid records the reference.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reimbursements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('expense_report_id')
                ->constrained('expense_reports');
            $table->string('reimbursement_number', 50)->unique();
            $table->uuid('user_id');
            $table->decimal('amount', 19, 4);
            $table->string('currency', 10);
            // pending|paid|cancelled
            $table->string('status', 16)->default('pending')->index();
            $table->string('method', 30)->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reimbursements');
    }
};
