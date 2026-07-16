<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_secrets', function (Blueprint $table) {
            $table->timestamp('grace_until')->nullable()->after('active')->index();
            $table->timestamp('rotated_at')->nullable()->after('grace_until');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_secrets', function (Blueprint $table) {
            $table->dropColumn(['grace_until', 'rotated_at']);
        });
    }
};
