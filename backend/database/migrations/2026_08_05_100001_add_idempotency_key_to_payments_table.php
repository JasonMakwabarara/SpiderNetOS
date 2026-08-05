<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| PaymentService::recordPayment() and PaymentController both rely on
| payments.idempotency_key, but the original payments migration never
| created the column — any request carrying a key raised a SQL error.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable();
            $table->unique(['tenant_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
