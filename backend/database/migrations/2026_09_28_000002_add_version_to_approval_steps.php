<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The version each chain step approved.
 *
 * A chain completes only if every step that approved it approved the same
 * version — the one it then binds. Without a record per step, an approval
 * given to one version could be carried forward onto different content by a
 * later step that saw the new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_steps', function (Blueprint $table) {
            $table->string('version_hash', 71)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('approval_steps', function (Blueprint $table) {
            $table->dropColumn('version_hash');
        });
    }
};
