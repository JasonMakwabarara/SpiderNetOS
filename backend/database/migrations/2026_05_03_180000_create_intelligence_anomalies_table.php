<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligence_anomalies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('fingerprint', 191);
            $table->string('severity', 16)->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestampTz('detected_at');
            $table->timestampTz('resolved_at')->nullable()->index();
            $table->uuid('acknowledged_by')->nullable();
            $table->string('source', 32)->index();
            $table->string('source_ref', 64)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'fingerprint']);
            $table->index(['tenant_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intelligence_anomalies');
    }
};
