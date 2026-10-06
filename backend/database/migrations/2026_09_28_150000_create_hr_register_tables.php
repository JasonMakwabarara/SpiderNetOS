<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR register on the canonical schema. Employee numbers use the existing
 * document_sequences table (sequence_type, next_number). This migration
 * does not create a second sequence table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('employee_number', 50);
            $table->string('name');
            $table->string('first_name');
            $table->string('surname');
            $table->string('position_title');
            $table->text('job_description')->nullable();
            $table->date('start_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('inactive_from')->nullable();
            $table->string('inactive_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'employee_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'department_id']);
        });

        Schema::create('hr_audit_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->uuid('actor_user_id')->nullable()->index();
            $table->string('event', 64);
            $table->string('field', 64)->nullable();
            $table->text('previous_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'employee_id', 'created_at']);
        });

        Schema::create('clock_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->string('type', 8);
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['tenant_id', 'recorded_at']);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('tag', 64);
            $table->string('name');
            $table->string('status', 20)->default('available');
            $table->timestamps();
            $table->unique(['tenant_id', 'tag']);
        });

        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('asset_id')->constrained('assets');
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->timestamp('assigned_at');
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('clock_events');
        Schema::dropIfExists('hr_audit_entries');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('departments');
    }
};
