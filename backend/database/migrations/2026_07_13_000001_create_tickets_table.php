<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('tickets')) {
            Schema::create('tickets', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('title');
                $table->text('description');
                $table->string('status')->default('open');
                $table->string('priority')->default('medium');
                $table->uuid('created_by')->nullable();
                $table->uuid('assigned_to')->nullable();
                $table->timestamps();
            });
            echo " tickets table created\n";
        }
    }
    public function down() { Schema::dropIfExists('tickets'); }
};
