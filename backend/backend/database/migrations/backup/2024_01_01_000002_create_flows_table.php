<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('flows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->default('00000000-0000-0000-0000-000000000001');
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('dag')->nullable();
            $table->json('triggers')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            
            $table->index(['tenant_id', 'status']);
            $table->index('slug');
        });
    }

    public function down()
    {
        Schema::dropIfExists('flows');
    }
};
