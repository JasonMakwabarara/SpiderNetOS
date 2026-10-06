<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('credit_note_number');
            $table->text('reason')->nullable();
            $table->decimal('subtotal', 19, 4);
            $table->decimal('tax_amount', 19, 4)->default(0);
            $table->decimal('total_amount', 19, 4);
            $table->char('currency', 4);
            $table->string('status')->default('draft');
            $table->timestamps();

            $table->unique(['tenant_id', 'credit_note_number']);
        });

        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            $table->text('description');
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('line_total', 19, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_lines');
        Schema::dropIfExists('credit_notes');
    }
};
