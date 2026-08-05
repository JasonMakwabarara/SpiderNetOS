<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Stage 1c — Expense management (spend domain).
|
| expense_categories — per-tenant spend categories with advisory limits.
| expense_policies   — tenant/role-scoped advisory policies (limits, receipt
|                      thresholds, category allow-lists). Advisory only: they
|                      flag items, they never block submission.
| expense_reports    — the report aggregate (draft -> submitted ->
|                      awaiting_approval/approved -> reimbursed | rejected | void).
| expense_items      — line items on a report.
| spend_documents    — uploaded receipts/bills (polymorphic attachable),
|                      including the AI-extraction pipeline columns.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('slug', 60);
            $table->uuid('gl_account_id')->nullable();
            $table->decimal('per_expense_limit', 19, 4)->nullable();
            $table->decimal('monthly_limit', 19, 4)->nullable();
            $table->decimal('requires_receipt_over', 19, 4)->nullable();
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('expense_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('scope_type', 16)->default('tenant'); // tenant|role|department
            $table->string('scope_value')->nullable();
            $table->foreignUuid('category_id')
                ->nullable()
                ->constrained('expense_categories')
                ->nullOnDelete();
            $table->decimal('per_expense_limit', 19, 4)->nullable();
            $table->decimal('daily_limit', 19, 4)->nullable();
            $table->decimal('monthly_limit', 19, 4)->nullable();
            $table->decimal('receipt_required_over', 19, 4)->nullable();
            $table->json('allowed_categories')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled']);
        });

        Schema::create('expense_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->index();
            $table->string('report_number', 50)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            // draft|submitted|awaiting_approval|approved|rejected|reimbursed|void
            $table->string('status', 20)->default('draft')->index();
            $table->string('currency', 10)->default('USD');
            $table->decimal('total_amount', 19, 4)->default(0);
            $table->integer('policy_violation_count')->default(0);
            $table->uuid('approval_id')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('reimbursed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('expense_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('expense_report_id')
                ->constrained('expense_reports')
                ->cascadeOnDelete();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('category_id')
                ->nullable()
                ->constrained('expense_categories')
                ->nullOnDelete();
            $table->string('merchant')->nullable();
            $table->string('description');
            $table->date('expense_date');
            $table->decimal('amount', 19, 4);
            $table->string('currency', 10);
            $table->boolean('has_receipt')->default(false);
            $table->json('policy_flags')->nullable();
            $table->uuid('gl_account_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'expense_date']);
        });

        Schema::create('spend_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('uploaded_by')->nullable();
            $table->string('kind', 20); // receipt|bill
            $table->nullableUuidMorphs('attachable');
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            // uploaded|queued|extracting|extracted|needs_review|failed|confirmed
            $table->string('status', 20)->default('uploaded')->index();
            $table->json('extraction')->nullable();
            $table->string('extraction_method', 20)->nullable();
            $table->string('extraction_model')->nullable();
            $table->decimal('extraction_cost_usd', 10, 6)->nullable();
            $table->integer('extraction_latency_ms')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('error')->nullable();
            $table->uuid('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spend_documents');
        Schema::dropIfExists('expense_items');
        Schema::dropIfExists('expense_reports');
        Schema::dropIfExists('expense_policies');
        Schema::dropIfExists('expense_categories');
    }
};
