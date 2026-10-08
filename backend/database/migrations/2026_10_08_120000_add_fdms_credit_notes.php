<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_note_lines', function (Blueprint $table) {
            $table->foreignUuid('invoice_line_item_id')->nullable()->constrained('invoice_line_items')->nullOnDelete();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->decimal('discount_amount', 19, 4)->default(0);
        });

        Schema::table('fdms_receipts', function (Blueprint $table) {
            $table->foreignUuid('credit_note_id')->nullable()->constrained('credit_notes')->cascadeOnDelete();
            $table->dropUnique(['tenant_id', 'invoice_id']);
            $table->unique(['tenant_id', 'credit_note_id']);
        });

        // One invoice receipt per invoice; credit note receipts share the invoice id.
        DB::statement('CREATE UNIQUE INDEX fdms_receipts_invoice_receipt_unique ON fdms_receipts (tenant_id, invoice_id) WHERE credit_note_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fdms_receipts_invoice_receipt_unique');

        Schema::table('fdms_receipts', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'credit_note_id']);
            $table->dropConstrainedForeignId('credit_note_id');
            $table->unique(['tenant_id', 'invoice_id']);
        });

        Schema::table('credit_note_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_line_item_id');
            $table->dropColumn(['tax_rate', 'discount_amount']);
        });
    }
};
