<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('requester_id')->index();
            $table->string('approval_type', 32)->index();
            $table->string('resource_type', 32);
            $table->uuid('resource_id');
            $table->text('reason');
            $table->jsonb('context');
            $table->string('status', 16)->default('pending');
            $table->uuid('approver_id')->nullable()->index();
            $table->text('response')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'approval_type', 'status']);
        });
    }
    
    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
