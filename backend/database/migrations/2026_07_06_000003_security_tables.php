<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_secret')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('action');
            $table->string('ip')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamps();
        });
    }
    public function down() {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_enabled']);
        });
        Schema::dropIfExists('audit_logs');
    }
};
