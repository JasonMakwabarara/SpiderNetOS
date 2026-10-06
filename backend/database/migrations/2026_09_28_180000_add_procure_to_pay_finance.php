<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignUuid('purchase_order_id')->nullable()->unique()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignUuid('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
        });

        Schema::table('invoice_line_items', function (Blueprint $table) {
            $table->foreignUuid('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->nullOnDelete();
        });

        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->unique(['tenant_id', 'account_number']);
        });

        Schema::create('three_way_matches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders');
            $table->foreignUuid('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
            $table->string('status', 20);
            $table->timestamps();
        });

        Schema::create('three_way_match_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('three_way_match_id')->constrained('three_way_matches')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_line_id')->constrained('purchase_order_lines');
            $table->decimal('ordered_quantity', 19, 4);
            $table->decimal('received_quantity', 19, 4);
            $table->decimal('invoiced_quantity', 19, 4);
            $table->string('status', 20);
            $table->timestamps();
        });

        Schema::create('payables_postings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
            $table->uuid('transaction_id');
            $table->timestamps();
        });

        Schema::create('cashbooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('currency', 4);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('cashbook_id')->constrained('cashbooks')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 19, 4);
            $table->string('currency', 4);
            $table->date('movement_date');
            $table->timestamps();
        });

        Schema::create('fiscal_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
            $table->string('environment', 20);
            $table->string('status', 20);
            $table->string('fiscal_receipt_id', 40);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_submissions');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cashbooks');
        Schema::dropIfExists('payables_postings');
        Schema::dropIfExists('three_way_match_lines');
        Schema::dropIfExists('three_way_matches');
        Schema::table('financial_accounts', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'account_number']);
        });
        Schema::table('invoice_line_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_line_id');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
