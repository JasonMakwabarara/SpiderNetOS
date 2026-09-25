<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per tenant that has been linked to Hannah AI (plan D7 §1).
 *
 * The link is the whole hand-off: it records which Hannah user, workspace and
 * Company this tenant owns over there, so "I need to market this product"
 * lands the owner inside Hannah already signed in, with their brand already
 * there, and nothing re-entered.
 *
 * What is deliberately NOT stored: the Hannah password (there isn't one — SSO
 * is a single-use deep-link token), the webhook secret itself (only a
 * reference into the key manager), and anything about Hannah's billing beyond
 * the ids needed to read a balance.
 *
 * SQLite-safe: plain Blueprint calls, string jsonb defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hannah_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // One link per tenant: a tenant has exactly one Hannah workspace.
            $table->uuid('tenant_id')->unique();
            $table->uuid('linked_by')->nullable();

            // Identifiers on Hannah's side.
            $table->string('hannah_user_id', 64)->nullable();
            $table->string('hannah_workspace_id', 64)->nullable();
            $table->string('hannah_company_id', 64)->nullable();
            // Stable opaque id Hannah uses for this partner identity.
            $table->string('open_id', 128)->nullable();
            $table->string('owner_email', 190)->nullable();

            // TenantKeyManager reference, never the secret itself.
            $table->string('webhook_secret_ref', 190)->nullable();

            // sha256 of the last brand payload pushed, so an unchanged brain
            // does not re-push on every brain write.
            $table->string('last_brand_hash', 64)->nullable();
            $table->timestamp('last_brand_synced_at')->nullable();

            // pending | linked | failed | revoked
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();

            // Consent is a fact with a timestamp, not a boolean: the owner
            // accepted Hannah's terms at a moment, and we keep that moment.
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_accepted_by', 190)->nullable();

            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hannah_links');
    }
};
