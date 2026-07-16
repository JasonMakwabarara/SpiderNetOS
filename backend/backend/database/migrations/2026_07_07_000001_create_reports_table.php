<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        if (!Schema::hasTable('reports')) {
            Schema::create('reports', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('title');
                $table->string('type')->default('daily');
                $table->json('data')->nullable();
                $table->string('file_path')->nullable();
                $table->timestamp('generated_at')->nullable();
                $table->timestamps();
            });
            echo "? Reports table created\n";
        } else {
            echo "? Reports table already exists\n";
        }
    }
    public function down() {
        Schema::dropIfExists('reports');
    }
};
