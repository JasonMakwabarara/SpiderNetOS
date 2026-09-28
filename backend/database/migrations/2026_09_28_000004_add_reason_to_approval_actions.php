<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why an approval action did not end `done`, as a code a person or a query can
 * act on (ApprovalActions::REASON_*), beside the human-readable last_error.
 * varchar 32: the longest code is 19 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_actions', function (Blueprint $table) {
            $table->string('reason', 32)->nullable();
            $table->index(['status', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::table('approval_actions', function (Blueprint $table) {
            $table->dropIndex(['status', 'reason']);
            $table->dropColumn('reason');
        });
    }
};
