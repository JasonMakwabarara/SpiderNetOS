<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\PackEntitlement;
use App\Models\Tenant;
use App\Services\FeaturePackInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * C3: provisionPackAgents() reads packages/feature-packs/{pack}/prompts/
 * {agent}.md and stores the contents as config.system_prompt on the
 * provisioned agents row — generically, for any pack that ships prompts.
 */
class PackAgentPromptTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        $tenant = Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Prompted Co',
            'slug' => 'prompted-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);

        // sales-crm is a paid pack — grant the entitlement install() asserts.
        PackEntitlement::create([
            'tenant_id' => $tenant->id,
            'pack_id' => 'sales-crm',
            'source' => 'grant',
            'provider' => 'manual',
            'status' => 'active',
            'purchased_at' => now(),
        ]);

        return $tenant;
    }

    /**
     * @return array<string, mixed>
     */
    private function agentConfig(Tenant $tenant, string $slug): array
    {
        $raw = DB::table('agents')
            ->where('tenant_id', $tenant->id)
            ->where('slug', $slug)
            ->value('config');

        $this->assertNotNull($raw, "agent {$slug} was not provisioned");
        $config = json_decode((string) $raw, true);
        $this->assertIsArray($config);

        return $config;
    }

    public function test_sales_crm_agents_are_provisioned_with_pack_prompts(): void
    {
        $tenant = $this->createTenant();

        app(FeaturePackInstaller::class)->install($tenant, 'sales-crm', true);

        foreach (['sales_crm_crm', 'sales_crm_growth', 'sales_crm_retention', 'sales_crm_funnel_architect'] as $slug) {
            $config = $this->agentConfig($tenant, $slug);
            $this->assertSame('sales-crm', $config['pack_id']);
            $this->assertNotEmpty($config['system_prompt'] ?? '', "agent {$slug} has an empty config.system_prompt");
        }

        // The prompt content really came from the pack's prompts/ directory —
        // including the hyphen/underscore mapping (funnel_architect ->
        // funnel-architect.md).
        $this->assertStringContainsString('CRM agent', $this->agentConfig($tenant, 'sales_crm_crm')['system_prompt']);
        $this->assertStringContainsString('Funnel Architect', $this->agentConfig($tenant, 'sales_crm_funnel_architect')['system_prompt']);
    }

    public function test_business_systemization_sop_coach_gets_prompt_and_promptless_agents_do_not(): void
    {
        $tenant = $this->createTenant();

        // Free pack (no spec.pricing) — no entitlement required.
        app(FeaturePackInstaller::class)->install($tenant, 'business-systemization', true);

        $sopCoach = $this->agentConfig($tenant, 'business_systemization_sop_coach');
        $this->assertNotEmpty($sopCoach['system_prompt'] ?? '');
        $this->assertStringContainsString('SOP coach', $sopCoach['system_prompt']);

        // Agents without a prompt file keep the plain pack_id-only config.
        $mapper = $this->agentConfig($tenant, 'business_systemization_systems_mapper');
        $this->assertSame('business-systemization', $mapper['pack_id']);
        $this->assertArrayNotHasKey('system_prompt', $mapper);
    }

    public function test_reinstall_backfills_prompt_onto_existing_agents_without_overwriting(): void
    {
        $tenant = $this->createTenant();
        app(FeaturePackInstaller::class)->install($tenant, 'sales-crm', true);

        // Simulate an agent provisioned before the pack shipped prompts, and
        // one whose owner customised the prompt.
        DB::table('agents')->where('tenant_id', $tenant->id)->where('slug', 'sales_crm_crm')
            ->update(['config' => json_encode(['pack_id' => 'sales-crm'])]);
        DB::table('agents')->where('tenant_id', $tenant->id)->where('slug', 'sales_crm_growth')
            ->update(['config' => json_encode(['pack_id' => 'sales-crm', 'system_prompt' => 'CUSTOMISED'])]);

        app(FeaturePackInstaller::class)->install($tenant, 'sales-crm', true);

        $this->assertStringContainsString('CRM agent', $this->agentConfig($tenant, 'sales_crm_crm')['system_prompt'] ?? '');
        $this->assertSame('CUSTOMISED', $this->agentConfig($tenant, 'sales_crm_growth')['system_prompt'] ?? null);
    }
}
