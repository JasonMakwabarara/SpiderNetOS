<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('employment_type', 20)->default('permanent');
            $table->string('pay_basis', 20)->default('monthly');
            $table->decimal('pay_rate', 12, 2)->default(0);
            $table->string('pay_currency', 4)->default('USD');
            $table->string('national_id', 50)->nullable();
            $table->string('nssa_number', 50)->nullable();
            $table->uuid('reports_to')->nullable()->index();
            $table->uuid('user_id')->nullable();
            $table->string('jetson_person_id', 64)->nullable();
            $table->unique(['tenant_id', 'user_id'], 'employees_tenant_user_unique');
            $table->unique(['tenant_id', 'jetson_person_id'], 'employees_tenant_jetson_unique');
        });

        Schema::table('clock_events', function (Blueprint $table) {
            $table->string('source', 16)->default('manual');
            $table->uuid('attendance_site_id')->nullable()->index();
            $table->uuid('attendance_device_id')->nullable()->index();
            $table->string('external_event_id', 64)->nullable();
            $table->string('sensor_id', 64)->nullable();
            $table->unique(['tenant_id', 'external_event_id'], 'clock_events_tenant_external_unique');
        });

        Schema::create('attendance_sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('secret_hash', 64);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_cursor', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('attendance_site_id')->nullable()->constrained('attendance_sites')->nullOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->string('external_device_id', 128);
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'external_device_id'], 'attendance_devices_tenant_external_unique');
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('code', 32);
            $table->string('name');
            $table->boolean('paid')->default(true);
            $table->unsignedSmallInteger('default_days')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('leave_type_id')->constrained('leave_types');
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('entitled')->default(0);
            $table->unsignedSmallInteger('used')->default(0);
            $table->timestamps();
            $table->unique(['employee_id', 'leave_type_id', 'year'], 'leave_balances_employee_type_year');
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('leave_type_id')->constrained('leave_types');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('days');
            $table->string('status', 16)->default('pending');
            $table->uuid('approval_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('statutory_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->string('currency', 4);
            $table->date('effective_from');
            $table->json('definition');
            $table->timestamps();
            $table->index(['tenant_id', 'currency', 'effective_from']);
        });

        Schema::create('pay_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('currency', 4);
            $table->string('status', 16)->default('draft');
            $table->uuid('approval_id')->nullable();
            $table->uuid('statutory_rule_id')->nullable();
            $table->json('summary')->nullable();
            $table->string('journal_transaction_id')->nullable();
            $table->timestamps();
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('pay_run_id')->constrained('pay_runs')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->unsignedInteger('payable_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedSmallInteger('unpaid_leave_days')->default(0);
            $table->decimal('gross', 12, 2);
            $table->decimal('net', 12, 2);
            $table->json('lines');
            $table->timestamps();
            $table->unique(['pay_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('pay_runs');
        Schema::dropIfExists('statutory_rules');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_types');
        Schema::dropIfExists('attendance_devices');
        Schema::dropIfExists('attendance_sites');

        Schema::table('clock_events', function (Blueprint $table) {
            $table->dropUnique('clock_events_tenant_external_unique');
            $table->dropColumn([
                'source',
                'attendance_site_id',
                'attendance_device_id',
                'external_event_id',
                'sensor_id',
            ]);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_tenant_user_unique');
            $table->dropUnique('employees_tenant_jetson_unique');
            $table->dropColumn([
                'employment_type',
                'pay_basis',
                'pay_rate',
                'pay_currency',
                'national_id',
                'nssa_number',
                'reports_to',
                'user_id',
                'jetson_person_id',
            ]);
        });
    }
};
