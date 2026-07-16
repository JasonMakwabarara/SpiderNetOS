<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform billing: SpiderNetOS charging the tenant (platform fee + metered
 * usage overage). Deliberately named distinctly from the financial-vertical
 * `subscriptions`/`invoices` tables (2026_05_07), which are the tenant's OWN
 * accounting of THEIR customers — reusing those would collide on the
 * Subscription/Invoice models.
 *
 * Pricing model (owner-approved 2026-07-16): a flat monthly platform fee that
 * includes a usage allowance, plus metered overage on real LLM/tool cost at
 * cost + `usage_margin_pct`. Catalog values are DB data (seeded by
 * PlanCatalogSeeder) so they can be tuned without a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Catalog of purchasable plans. String PK (launch|growth|enterprise).
        Schema::create('plans', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('name', 64);
            $table->string('tagline', 200)->nullable();
            $table->unsignedInteger('monthly_fee_cents')->default(0);
            $table->unsignedInteger('included_usage_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('dodo_product_id', 128)->nullable();
            $table->unsignedSmallInteger('usage_margin_pct')->default(15);
            // Custom = contact-sales / negotiated annual (enterprise): fee/usage
            // are placeholders, real terms live on the tenant_subscription.
            $table->boolean('is_custom')->default(false);
            // {agents, flows, seats, pack_slots} — -1 means unlimited.
            $table->jsonb('entitlements')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // A tenant's current plan subscription. One live row per tenant.
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('plan_id', 32);
            $table->string('dodo_subscription_id', 128)->nullable()->unique();
            $table->string('dodo_customer_id', 128)->nullable();
            // trialing | active | past_due | cancelled
            $table->string('status', 16)->default('trialing');
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_start')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestampTz('cancelled_at')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // At most one non-terminal subscription per tenant.
        DB::statement("
            CREATE UNIQUE INDEX tenant_subscriptions_live_unique
              ON tenant_subscriptions (tenant_id)
              WHERE status IN ('trialing', 'active', 'past_due')
        ");

        // One invoice per tenant per billing period (fee + overage).
        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('plan_id', 32)->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('platform_fee_cents')->default(0);
            $table->unsignedInteger('included_usage_cents')->default(0);
            // Raw metered LLM/tool cost in the period (pre-margin).
            $table->unsignedInteger('metered_usage_cents')->default(0);
            // Billable overage after margin: max(0, metered - included) * (1 + margin).
            $table->unsignedInteger('overage_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->string('currency', 3)->default('USD');
            // draft | open | paid | void
            $table->string('status', 16)->default('draft');
            $table->string('provider_payment_id', 128)->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'period_start', 'period_end']);
        });

        Schema::create('platform_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id')->index();
            // platform_fee | usage_overage | credit | adjustment
            $table->string('kind', 24);
            $table->string('description', 200);
            $table->integer('amount_cents');
            $table->jsonb('meta')->default('{}');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoice_lines');
        Schema::dropIfExists('platform_invoices');
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('plans');
    }
};
