<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approval authorises exactly the version its approver saw.
 *
 * `version_hash` is the canonical hash of the payload the approval currently
 * covers. It is set when the resource is submitted and replaced when the
 * resource is edited while pending, which supersedes the version an earlier
 * reviewer saw. `approved_version_hash` is what the decision bound, and it
 * is what the applier checks the payload against before applying anything.
 * Both are nullable: only resource types with a version binding
 * (config/approvals.php `version_bindings`) use them, and an approval of
 * such a type whose `version_hash` is null is refused rather than applied
 * unbound.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            // 'sha256:' + 64 hex characters.
            $table->string('version_hash', 71)->nullable();
            $table->string('approved_version_hash', 71)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn(['version_hash', 'approved_version_hash']);
        });
    }
};
