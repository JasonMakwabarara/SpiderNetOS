<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fdms_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedInteger('device_id');
            $table->string('base_url');
            $table->string('fiscal_day_status')->default('FiscalDayClosed');
            $table->unsignedInteger('fiscal_day_no')->nullable();
            $table->dateTime('fiscal_day_opened_at')->nullable();
            $table->unsignedInteger('receipt_counter')->default(0);
            $table->unsignedBigInteger('receipt_global_no')->default(0);
            $table->string('previous_receipt_hash', 64)->nullable();
            $table->dateTime('last_receipt_date')->nullable();
            $table->json('counters')->nullable();
            $table->string('qr_url')->nullable();
            $table->json('applicable_taxes')->nullable();
            $table->string('vat_number', 20)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'device_id']);
        });

        Schema::create('fdms_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('fdms_device_id')->constrained('fdms_devices')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('receipt_type', 20);
            $table->unsignedInteger('fiscal_day_no');
            $table->unsignedInteger('receipt_counter');
            $table->unsignedBigInteger('receipt_global_no');
            $table->string('receipt_hash', 64);
            $table->text('receipt_signature');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('fdms_receipt_id')->nullable();
            $table->string('operation_id', 60)->nullable();
            $table->dateTime('server_date')->nullable();
            $table->string('qr_data')->nullable();
            $table->json('validation_errors')->nullable();
            $table->string('error_code', 20)->nullable();
            $table->timestamps();

            $table->unique(['fdms_device_id', 'receipt_global_no']);
            $table->unique(['tenant_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fdms_receipts');
        Schema::dropIfExists('fdms_devices');
    }
};
