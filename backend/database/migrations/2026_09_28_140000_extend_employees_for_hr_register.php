<?php

use App\Support\EmployeeNameBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('first_name')->default('');
            $table->string('surname')->default('');
            $table->string('position_title')->default('');
            $table->text('job_description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('inactive_from')->nullable();
            $table->string('inactive_reason')->nullable();
            $table->index(['tenant_id', 'department_id']);
        });

        DB::table('employees')->orderBy('id')->select('id', 'name')->each(function (object $row): void {
            $split = EmployeeNameBackfill::split((string) $row->name);
            DB::table('employees')->where('id', $row->id)->update($split);
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
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_audit_entries');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'department_id']);
            $table->dropColumn([
                'first_name',
                'surname',
                'position_title',
                'job_description',
                'start_date',
                'inactive_from',
                'inactive_reason',
            ]);
        });
    }
};
