<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // config/queue.php stores job batches in job_batches, but no migration
        // ever created the table, so Bus::batch() and queue:prune-batches
        // would throw on first use. A no-op wherever the table already exists.
        if (Schema::hasTable('job_batches')) {
            return;
        }

        // Laravel 11 shape (Illuminate/Queue/Console/stubs/batches.stub).
        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_batches');
    }
};
