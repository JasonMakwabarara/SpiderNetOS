<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Stage 2 — Bill pay / AP (spend domain).
|
| bills           — the payable aggregate (draft -> awaiting_approval/approved
|                   -> scheduled -> paid | void). Numbers come from
|                   DocumentNumberService per tenant, so uniqueness is scoped
|                   (tenant_id, bill_number) — two tenants share the same
|                   BILL-YYYYMMDD-NNNNNN sequence values.
| bill_line_items — line items on a bill.
| payments        — gains bill_id so outgoing payments link back to the bill.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('vendor_id')->nullable()->index();
            $table->string('bill_number', 50);
            $table->string('vendor_invoice_ref')->nullable();
            // draft|awaiting_approval|approved|scheduled|paid|void
            $table->string('status', 20)->default('draft')->index();
            $table->decimal('subtotal', 19, 4)->default(0);
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('total_amount', 19, 4)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->date('issue_date')->nullable();
            $table->date('due_date');
            $table->date('scheduled_for')->nullable();
            $table->date('paid_at')->nullable();
            $table->date('void_at')->nullable();
            $table->uuid('approval_id')->nullable();
            $table->uuid('payment_id')->nullable();
            // manual|upload|email
            $table->string('source', 16)->default('manual');
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'bill_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'scheduled_for']);
        });

        Schema::create('bill_line_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bill_id')
                ->constrained('bills')
                ->cascadeOnDelete();
            $table->uuid('tenant_id')->index();
            $table->string('description');
            $table->decimal('quantity', 19, 4)->default(1);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('total', 19, 4);
            $table->uuid('category_id')->nullable();
            $table->uuid('gl_account_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('bill_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('bill_id');
        });
        Schema::dropIfExists('bill_line_items');
        Schema::dropIfExists('bills');
    }
};
