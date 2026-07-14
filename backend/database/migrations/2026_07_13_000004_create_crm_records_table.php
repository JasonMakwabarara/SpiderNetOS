<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('crm_records')) {
            Schema::create('crm_records', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
                $table->string('field');
                $table->text('value');
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
            });
            echo " crm_records table created\n";
        } else {
            echo " crm_records table already exists\n";
        }
    }
    public function down() { Schema::dropIfExists('crm_records'); }
};
