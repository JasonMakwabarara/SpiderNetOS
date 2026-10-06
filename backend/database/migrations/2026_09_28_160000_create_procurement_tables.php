<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement on the canonical schema.
 *
 * Numbers use document_sequences (sequence_type requisition / purchase_order).
 * Vendors are the existing spend directory. Invoices are not altered:
 * an approved requisition is approved for procurement, not converted
 * into an invoice or a purchase order by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('requisition_number', 32);
            $table->string('title');
            $table->string('status', 20)->default('draft')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'requisition_number']);
        });

        Schema::create('requisition_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('vendor_id')->constrained('vendors');
            $table->foreignUuid('requisition_id')->nullable()->constrained('requisitions')->nullOnDelete();
            $table->string('po_number', 32);
            $table->string('status', 20)->default('draft')->index();
            $table->string('currency', 10);
            $table->decimal('amount', 19, 4);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'po_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('requisition_lines');
        Schema::dropIfExists('requisitions');
    }
};
