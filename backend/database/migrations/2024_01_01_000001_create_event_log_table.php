<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('aggregate_type', 64)->index();
            $table->uuid('aggregate_id')->index();
            $table->string('event_type', 128)->index();
            $table->jsonb('payload');
            $table->jsonb('metadata')->nullable();
            $table->integer('version');
            $table->timestamp('occurred_at')->index();
            $table->bigInteger('sequence_num')->unique();
            $table->string('hash', 64)->nullable()->index();
            $table->string('previous_hash', 64)->nullable();

            $table->unique(['aggregate_type', 'aggregate_id', 'version']);
            $table->index(['tenant_id', 'aggregate_type', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        Schema::create('event_sequence', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('next_num')->default(1);
            $table->timestamps();
        });

        DB::table('event_sequence')->insert(['next_num' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('event_log');
        Schema::dropIfExists('event_sequence');
    }
};
