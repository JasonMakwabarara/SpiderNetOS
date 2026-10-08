<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
        });

        Schema::create('job_description_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('title_key');
            $table->text('body');
            $table->timestamps();
            $table->unique(['tenant_id', 'title_key']);
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            $table->timestamps();
        });

        Schema::create('employee_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->date('effective_from');
            $table->timestamps();
            $table->index(['tenant_id', 'employee_id', 'effective_from']);
        });

        Schema::create('attendance_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->unsignedInteger('minutes')->default(0);
            $table->string('punctuality', 16);
            $table->timestamps();
            $table->unique(['tenant_id', 'employee_id', 'work_date']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('leave_type', 16);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('draft');
            $table->timestamps();
            $table->index(['tenant_id', 'employee_id', 'status']);
        });

        Schema::create('employment_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->string('contract_number', 50);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->decimal('pay_amount', 19, 4);
            $table->char('currency', 4);
            $table->string('pay_period', 8);
            $table->string('status', 16)->default('draft');
            $table->timestamps();
            $table->unique(['tenant_id', 'contract_number']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 4);
            $table->string('status', 16)->default('draft');
            $table->timestamps();
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('contract_id')->constrained('employment_contracts')->cascadeOnDelete();
            $table->unsignedInteger('minutes')->default(0);
            $table->decimal('amount', 19, 4);
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->date('acquired_on')->nullable();
            $table->decimal('cost', 19, 4)->nullable();
            $table->decimal('residual_value', 19, 4)->nullable();
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->string('depreciation_method', 20)->nullable();
        });

        Schema::create('depreciation_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreignUuid('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->char('period', 7);
            $table->decimal('amount', 19, 4);
            $table->string('status', 16)->default('posted');
            $table->timestamps();
            $table->unique(['asset_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_entries');
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn(['acquired_on', 'cost', 'residual_value', 'useful_life_months', 'depreciation_method']);
        });
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employment_contracts');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('attendance_days');
        Schema::dropIfExists('employee_shifts');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('job_description_templates');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });
        Schema::dropIfExists('positions');
    }
};
