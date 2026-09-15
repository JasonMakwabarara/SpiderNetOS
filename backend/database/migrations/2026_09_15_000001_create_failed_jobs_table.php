<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // config/queue.php logs failures to failed_jobs (database-uuids), but
        // no migration ever created the table: a job that ran out of tries
        // threw inside the failed-job provider and its record was lost, and
        // queue:failed / queue:retry could not run. A no-op wherever the
        // table already exists.
        if (Schema::hasTable('failed_jobs')) {
            return;
        }

        // Laravel 11 shape (Illuminate/Queue/Console/stubs/failed_jobs.stub).
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};
