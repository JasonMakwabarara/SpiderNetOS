<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Per-tenant, per-document-type number allocation. Replaces the
| COUNT(*)+1 generators in Invoice/Payment/Ledger services, which
| collide under concurrency against their unique number columns.
| Rows are locked FOR UPDATE inside the caller's transaction.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('sequence_type', 30);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'sequence_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
