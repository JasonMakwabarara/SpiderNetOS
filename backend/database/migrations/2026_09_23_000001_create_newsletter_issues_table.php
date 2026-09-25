<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per issue of either newsletter (plan D8 #15, #16):
 *
 *   csuite   — internal, one recipient, rides with the Monday letter, never
 *              leaves SpiderNet. `period` is the ISO week, "2026-W38".
 *   customer — public, every 12 days, always an approval before it goes out.
 *              `period` is the cadence slot's send date, "2026-09-29".
 *
 * The quote_id column is what keeps the C-Suite quote from repeating: the
 * bank excludes every quote used by this tenant in the last 26 issues.
 *
 * SQLite-safe: plain Blueprint calls, string jsonb defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();

            // csuite | customer
            $table->string('kind', 16);
            // ISO week for csuite ("2026-W38"), send date for customer ("2026-09-29").
            $table->string('period', 16);

            // draft | pending_approval | approved | sent | skipped | failed
            $table->string('status', 24)->default('draft');

            $table->string('subject', 200)->nullable();
            $table->string('preheader', 200)->nullable();
            $table->longText('markdown')->nullable();
            $table->longText('html')->nullable();

            // packages/content/quotes.yaml id — csuite only.
            $table->string('quote_id', 64)->nullable();
            // Brain path the issue was filed at, e.g. reports/weekly/2026-W38-csuite.md.
            $table->string('brain_path', 255)->nullable();

            $table->uuid('approval_id')->nullable();
            $table->uuid('agent_run_id')->nullable();
            // beehiiv | tenant_mailbox | cockpit_only
            $table->string('channel', 32)->nullable();
            $table->string('external_id', 128)->nullable();

            $table->integer('recipients')->default(0);
            // Opens/clicks/unsubscribes pulled back from the rail.
            $table->jsonb('stats')->default('{}');
            $table->jsonb('meta')->default('{}');

            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // One issue per tenant per kind per slot — the composer is idempotent.
            $table->unique(['tenant_id', 'kind', 'period']);
            $table->index(['tenant_id', 'kind', 'created_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_issues');
    }
};
