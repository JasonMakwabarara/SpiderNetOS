<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks purchase/grant of a feature pack, separate from `feature_packs`
 * (which is pure installation state). A tenant can be entitled without
 * having installed yet, and installation is gated on an active entitlement
 * for priced packs (see App\Services\FeaturePackInstaller).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pack_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('pack_id', 64)->index();
            $table->string('source', 16)->default('purchase'); // purchase | granted | trial | included_in_plan
            $table->string('provider', 16)->nullable(); // dodo
            $table->string('provider_payment_id', 128)->nullable();
            $table->string('provider_subscription_id', 128)->nullable();
            $table->string('provider_customer_id', 128)->nullable();
            $table->string('status', 16)->default('pending'); // pending active revoked refunded expired
            $table->unsignedInteger('amount_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->timestampTz('purchased_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->jsonb('raw_payload')->default('{}');
            $table->timestamps();

            $table->index(['tenant_id', 'pack_id', 'status']);
        });

        // Partial unique index: only one ACTIVE entitlement per (tenant, pack)
        // — pending/revoked/expired rows from prior attempts must not block
        // a fresh purchase.
        DB::statement("
            CREATE UNIQUE INDEX pack_entitlements_active_unique
              ON pack_entitlements (tenant_id, pack_id)
              WHERE status = 'active'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('pack_entitlements');
    }
};
