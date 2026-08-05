<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('invoice_number', 50)->unique();
            $table->uuid('customer_id')->nullable()->index();
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->decimal('subtotal', 19, 4);
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('discount_amount', 19, 4)->default(0);
            $table->decimal('total_amount', 19, 4);
            $table->string('currency', 10)->default('USD');
            $table->string('status', 20)->default('draft')->index();
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('paid_at')->nullable();
            $table->date('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->string('pdf_path')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
        });

        Schema::create('invoice_line_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id')->index();
            $table->string('description');
            $table->decimal('quantity', 19, 4)->default(1);
            $table->string('unit', 20)->default('each');
            $table->decimal('unit_price', 19, 4);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('total', 19, 4);
            $table->string('sku')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_line_items');
        Schema::dropIfExists('invoices');
    }
};
