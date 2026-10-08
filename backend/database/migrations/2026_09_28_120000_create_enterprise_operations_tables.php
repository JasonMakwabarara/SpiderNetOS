<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The August sequence migration already owns this table on main.
        if (! Schema::hasTable('document_sequences')) {
            Schema::create('document_sequences', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('sequence_type', 30);
                $table->unsignedBigInteger('next_number')->default(1);
                $table->timestamps();
                $table->unique(['tenant_id', 'sequence_type']);
            });
        }

        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('employee_number', 50);
            $table->string('name');
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'employee_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('clock_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->string('type', 8);
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['tenant_id', 'recorded_at']);
        });

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

        if (! Schema::hasTable('vendors')) {
            Schema::create('vendors', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('name');
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->timestamps();
            });
        }

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

        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('tag', 64);
            $table->string('name');
            $table->string('status', 20)->default('available')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'tag']);
        });

        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('asset_id')->constrained('assets');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->timestamp('assigned_at');
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cashbooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('currency', 10);
            $table->timestamps();
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('cashbook_id')->constrained('cashbooks');
            $table->string('type', 16);
            $table->decimal('amount', 19, 4);
            $table->string('currency', 10);
            $table->date('movement_date');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->uuid('ledger_entry_id')->nullable();
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignUuid('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->string('fiscal_status', 20)->default('none')->index();
            $table->timestamp('fiscalised_at')->nullable();
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->string('credit_note_number', 32);
            $table->text('reason')->nullable();
            $table->decimal('subtotal', 19, 4);
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('total_amount', 19, 4);
            $table->string('currency', 10);
            $table->string('status', 20)->default('draft')->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'credit_note_number']);
        });

        Schema::create('credit_note_line_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('total', 19, 4);
            $table->timestamps();
        });

        Schema::create('fiscal_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('device_identifier', 64);
            $table->string('status', 20)->default('inactive')->index();
            $table->text('credentials')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'device_identifier']);
        });

        Schema::create('fiscal_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('fiscal_device_id')->constrained('fiscal_devices');
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->date('fiscal_day');
            $table->unsignedInteger('receipt_counter');
            $table->string('verification_code')->nullable();
            $table->json('qr_payload')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('driver', 16);
            $table->boolean('is_live')->default(false);
            $table->timestamps();
            $table->index(['tenant_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_submissions');
        Schema::dropIfExists('fiscal_devices');
        Schema::dropIfExists('credit_note_line_items');
        Schema::dropIfExists('credit_notes');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
            $table->dropColumn(['fiscal_status', 'fiscalised_at']);
        });
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cashbooks');
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('requisition_lines');
        Schema::dropIfExists('requisitions');
        Schema::dropIfExists('clock_events');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('document_sequences');
    }
};
