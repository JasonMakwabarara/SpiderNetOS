<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Check if tables exist before creating them
        if (!Schema::hasTable('flows')) {
            Schema::create('flows', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
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
        
        if (!Schema::hasTable('agents')) {
            Schema::create('agents', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('type')->default('custom');
                $table->string('status')->default('active');
                $table->json('capabilities')->nullable();
                $table->json('config')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamps();
                
                $table->index(['tenant_id', 'status']);
                $table->index('slug');
            });
        }
        
        if (!Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('domain')->nullable();
                $table->string('status')->default('active');
                $table->string('plan')->default('free');
                $table->json('settings')->nullable();
                $table->json('limits')->nullable();
                $table->timestamps();
            });
        }
        
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->string('role')->default('user');
                $table->json('capabilities')->nullable();
                $table->timestamp('email_verified_at')->nullable();
                $table->rememberToken();
                $table->timestamps();
                
                $table->index(['tenant_id', 'email']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('flows');
        Schema::dropIfExists('agents');
        Schema::dropIfExists('users');
        Schema::dropIfExists('tenants');
    }
};
