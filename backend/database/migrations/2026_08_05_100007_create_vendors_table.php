<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Stage 2 — Bill pay / AP (spend domain).
|
| vendors — the payee directory (mirror of the customers table shape).
| bank_details are stored as an opaque json blob (record-only V1: money
| movement happens outside the system, we never charge these details).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();
            $table->string('tax_id')->nullable();
            $table->json('address')->nullable();
            $table->string('currency', 10)->default('USD');
            $table->unsignedInteger('payment_terms_days')->nullable();
            $table->uuid('default_gl_account_id')->nullable();
            $table->json('bank_details')->nullable();
            // active|archived
            $table->string('status', 20)->default('active')->index();
            $table->string('provider_vendor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
