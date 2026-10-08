<?php

declare(strict_types=1);

namespace Tests\Feature\Skills;

use App\Models\Agent;
use App\Models\AgentWorkspace;
use App\Models\FeaturePack;
use App\Models\Tenant;
use App\Models\TenantSkill;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use App\Services\Skills\SkillRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * /api/skills: the catalogue lists the shipped cards with pillar meta, the card
 * JSON carries the ten sections, enable provisions tenant_skills + the
 * identity's agents row + its workspace, and the pipeline PUT gates
 * autonomous on the tenant's automation level.
 */
class SkillsApiTest extends TestCase
{
    use RefreshDatabase;

    public const TEN_SECTIONS = [
        'at_a_glance', 'covers', 'breaks_into', 'builds_on', 'replaces', 'pipeline', 'your_role', 'one_step_further', 'brain', 'hands_off_to',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        SkillRegistry::flush();
    }

    private function createTenant(string $automation = 'manual'): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Northbeam Books',
            'slug' => 'northbeam-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'plan' => 'pro',
            'automation_level' => $automation,
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createUser(Tenant $tenant): User
    {
        return User::create([
            'name' => 'Thandi',
            'email' => 'thandi@'.Str::lower(Str::random(8)).'.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function installSalesPack(Tenant $tenant): FeaturePack
    {
        return FeaturePack::create([
            'tenant_id' => $tenant->id,
            'pack_id' => 'sales-crm',
            'version' => '0.2.0',
            'vertical' => 'sales',
            'display_name' => 'Lead-to-Sale Funnel',
            'status' => 'installed',
            'installed_at' => now(),
            'manifest' => ['apiVersion' => 'spidernet/v1', 'kind' => 'FeaturePack', 'metadata' => ['id' => 'sales-crm'], 'spec' => []],
        ]);
    }

    private function brainFile(Tenant $tenant, string $path, string $content): void
    {
        DB::table('brain_files')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'path' => $path,
            'title' => $path,
            'content' => $content,
            'frontmatter' => '{}',
            'source' => 'human',
            'managed' => false,
            'data_class' => 'internal',
            'version' => 1,
            'content_hash' => hash('sha256', $content),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/skills')->assertUnauthorized();
        $this->getJson('/api/skills/cold-email-drafting')->assertUnauthorized();
    }

    public function test_catalogue_lists_the_shipped_cards_with_meta(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/skills');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(count(SkillRegistryTestSlugs::FIRST_RELEASE), $data);
        $this->assertSame(SkillRegistryTestSlugs::FIRST_RELEASE, array_column($data, 'slug'));

        $first = $data[0];
        foreach (['slug', 'name', 'pillar', 'map_node', 'core_agent', 'identity', 'runs_on', 'pack_id', 'at_a_glance', 'run_kind', 'enabled', 'stage', 'entitlement', 'brain', 'entry_path'] as $key) {
            $this->assertArrayHasKey($key, $first, $key);
        }
        $this->assertFalse($first['enabled']);
        $this->assertSame('human_led', $first['stage']);
        $this->assertSame('missing', $first['brain']['status']);

        $meta = $response->json('meta');
        $this->assertSame(['key', 'label', 'order'], array_keys($meta['pillars'][0]));
        $this->assertSame('sales', $meta['pillars'][0]['key']);
        $this->assertSame(9, count($meta['pillars']));
        $identityKeys = array_column($meta['identities'], 'key');
        $this->assertContains('growth', $identityKeys);
        $this->assertContains('richard', $identityKeys);
        $this->assertSame(['atlas', 'hannah', 'forge', 'sentinel', 'prism', 'nexus'], array_column($meta['core_agents'], 'slug'));
        $this->assertSame(count(SkillRegistryTestSlugs::FIRST_RELEASE), $meta['total']);
    }

    public function test_catalogue_filters(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $sales = $this->actingAs($user, 'sanctum')->getJson('/api/skills?pillar=sales')->assertOk()->json('data');
        $this->assertNotEmpty($sales);
        $this->assertSame(['sales'], array_values(array_unique(array_column($sales, 'pillar'))));

        $deals = $this->actingAs($user, 'sanctum')->getJson('/api/skills?pillar=deals')->assertOk()->json('data');
        $this->assertSame(['meeting-booking'], array_column($deals, 'slug'));

        $q = $this->actingAs($user, 'sanctum')->getJson('/api/skills?q=linkedin')->assertOk()->json('data');
        $this->assertContains('linkedin-outreach-specialist', array_column($q, 'slug'));
        $this->assertNotContains('meeting-booking', array_column($q, 'slug'));

        $this->actingAs($user, 'sanctum')->getJson('/api/skills?pillar=nonsense')->assertStatus(422);

        $enabled = $this->actingAs($user, 'sanctum')->getJson('/api/skills?enabled=1')->assertOk()->json('data');
        $this->assertSame([], $enabled);
    }

    /** Plans may include pack slots (PlanEntitlementService::packIncluded); pin it off so the gate is exercised. */
    private function withoutPlanPackSlots(): void
    {
        $this->mock(PlanEntitlementService::class)
            ->shouldReceive('packIncluded')
            ->andReturn(false);
    }

    public function test_card_json_has_the_ten_sections(): void
    {
        $this->withoutPlanPackSlots();
        $tenant = $this->createTenant('assisted');
        $user = $this->createUser($tenant);
        $this->brainFile($tenant, 'brand/voice.md', "## Tone\nWarm, direct, slightly dry. Short sentences, no fuss, a bookkeeper who has seen your kind of mess before.\n\n## Do and don't\nNever exclamation marks. Never leverage. Never say seamless or game-changer, ever.\n");

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/skills/cold-email-drafting');

        $response->assertOk();
        $card = $response->json('data');
        foreach (self::TEN_SECTIONS as $section) {
            $this->assertArrayHasKey($section, $card, "card is missing section {$section}");
        }
        foreach (['inputs', 'run_cta', 'identity', 'core_agent', 'entitlement', 'recent_runs'] as $key) {
            $this->assertArrayHasKey($key, $card, $key);
        }

        $this->assertStringContainsString('problem → proof → close', $card['at_a_glance']);
        $this->assertSame(['slug', 'name', 'does', 'available', 'enabled'], array_keys($card['breaks_into'][0]));
        $this->assertSame('salary', $card['replaces'][0]['kind']);

        $this->assertSame('human_led', $card['pipeline']['stage']);
        $this->assertTrue($card['pipeline']['inherited']);
        $this->assertSame('assisted', $card['pipeline']['tenant_default']);
        $this->assertSame(['human_led', 'assisted', 'autonomous'], $card['pipeline']['allowed']);
        $this->assertArrayHasKey('human_led', $card['pipeline']['stage_copy']);
        $this->assertSame(20, $card['pipeline']['promotion_gate']['clean_drafts']);
        $this->assertSame($card['pipeline']['stage_copy']['human_led']['your_role'], $card['your_role']);

        $this->assertCount(2, $card['one_step_further']['steps']);
        $this->assertSame('follow-up-drafting', $card['one_step_further']['steps'][0]['skill']);
        $this->assertTrue($card['one_step_further']['steps'][0]['available']);

        $files = collect($card['brain']['files'])->keyBy('path');
        $this->assertSame('ready', $files['brand/voice.md']['status']);
        $this->assertTrue($files['brand/voice.md']['required']);
        $this->assertSame('missing', $files['offer/offer.md']['status']);
        $this->assertNotNull($files['offer/offer.md']['ask_prompt'], 'a missing file carries the manifest question');
        $this->assertSame('optional', $files['notes/research/**']['status'] ?? 'optional');

        $handOffs = collect($card['hands_off_to'])->keyBy('name');
        $this->assertTrue($handOffs['Follow-up Drafting']['available']);
        $this->assertSame('/skills/follow-up-drafting', $handOffs['Follow-up Drafting']['entry_path']);
        $this->assertSame('identity', $handOffs['Richard']['type']);
        // Richard's identity went existing -> the hand-off target is real. `available`
        // here means the target exists in the catalogue, not that this tenant may use
        // it: the card reports entitlement separately, and asserts it is false below.
        $this->assertTrue($handOffs['Richard']['available']);

        $this->assertSame('object', $card['inputs']['type']);
        $this->assertFalse($card['run_cta']['enabled'], 'no pack + missing brain → run disabled with a reason');
        $this->assertNotNull($card['run_cta']['reason']);
        $this->assertSame('/api/skills/cold-email-drafting/run', $card['run_cta']['endpoint']);
        $this->assertSame('growth', $card['identity']['key']);
        $this->assertSame('nexus', $card['core_agent']['slug']);
        $this->assertFalse($card['entitlement']['entitled']);
        $this->assertSame('sales-crm', $card['entitlement']['pack_id']);
        $this->assertSame([], $card['recent_runs']);
    }

    public function test_linkedin_card_marks_richard_hand_off_unavailable(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $card = $this->actingAs($user, 'sanctum')->getJson('/api/skills/linkedin-outreach-specialist')->assertOk()->json('data');

        $richard = collect($card['hands_off_to'])->firstWhere('slug', 'linkedin-campaign-runner');
        $this->assertFalse($richard['available']);
        $this->assertTrue($richard['planned']);
        $this->assertSame(['human_led', 'assisted'], $card['pipeline']['allowed']);
    }

    public function test_unknown_slug_is_404(): void
    {
        $user = $this->createUser($this->createTenant());

        $this->actingAs($user, 'sanctum')->getJson('/api/skills/no-such-skill')->assertNotFound();
        $this->actingAs($user, 'sanctum')->postJson('/api/skills/no-such-skill/enable')->assertNotFound();
    }

    public function test_enable_requires_the_pack_entitlement(): void
    {
        $this->withoutPlanPackSlots();
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/skills/cold-email-drafting/enable');

        $response->assertStatus(402);
        $this->assertSame('sales-crm', $response->json('pack_id'));
        $this->assertSame('/feature-packs?pack=sales-crm', $response->json('checkout_hint'));
        $this->assertSame(0, TenantSkill::count());
    }

    public function test_enable_creates_tenant_skill_agent_and_workspace(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $this->installSalesPack($tenant);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/skills/cold-email-drafting/enable');

        $response->assertOk();
        $this->assertTrue($response->json('tenant_skill.enabled'));
        $this->assertSame('human_led', $response->json('tenant_skill.autonomy_level'));
        $this->assertTrue($response->json('data.tenant_skill.enabled'));
        $this->assertFalse($response->json('data.pipeline.inherited'));

        $row = TenantSkill::forTenant($tenant->id)->where('skill_slug', 'cold-email-drafting')->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->enabled);
        $this->assertSame('catalogue', $row->state['installed_from']);

        $agent = Agent::where('tenant_id', $tenant->id)->where('slug', 'sales_crm_growth')->first();
        $this->assertNotNull($agent, 'the growth identity gets its agents row');
        $this->assertSame('active', $agent->status);
        $this->assertSame($agent->id, $row->agent_id);
        $this->assertContains('cold-email-drafting', $agent->config['skills']);
        $this->assertStringContainsString('Growth Agent', (string) $agent->config['system_prompt'], 'the pack prompt is snapshotted');

        $workspace = AgentWorkspace::where('tenant_id', $tenant->id)->where('agent_id', $agent->id)->first();
        $this->assertNotNull($workspace, 'one workspace per tenant × identity');
        $this->assertSame('growth', $workspace->slug);
        $this->assertSame('workspaces/growth/drafts', $workspace->drafts_root);
        $this->assertSame($workspace->id, $row->workspace_id);
        $this->assertContains('offer/offer.md', $workspace->pinned_brain_paths);
        $this->assertSame(['id' => $workspace->id, 'slug' => 'growth', 'status' => 'idle'], $response->json('data.identity.workspace'));
    }

    public function test_enable_is_idempotent_and_shares_the_identity_workspace(): void
    {
        $tenant = $this->createTenant('assisted');
        $user = $this->createUser($tenant);
        $this->installSalesPack($tenant);

        $this->actingAs($user, 'sanctum')->postJson('/api/skills/cold-email-drafting/enable')->assertOk();
        $this->actingAs($user, 'sanctum')->putJson('/api/skills/cold-email-drafting/pipeline', ['stage' => 'assisted'])->assertOk();
        $this->actingAs($user, 'sanctum')->postJson('/api/skills/cold-email-drafting/enable')->assertOk();
        // LinkedIn runs on the same identity (growth) → same agent, same workspace.
        $this->actingAs($user, 'sanctum')->postJson('/api/skills/linkedin-outreach-specialist/enable')->assertOk();

        $this->assertSame(2, TenantSkill::forTenant($tenant->id)->count());
        $this->assertSame(1, Agent::where('tenant_id', $tenant->id)->where('slug', 'sales_crm_growth')->count());
        $this->assertSame(1, AgentWorkspace::where('tenant_id', $tenant->id)->count());
        $this->assertSame('assisted', TenantSkill::forTenant($tenant->id)->where('skill_slug', 'cold-email-drafting')->value('autonomy_level'), 're-enabling keeps the ladder position');

        $agent = Agent::where('tenant_id', $tenant->id)->where('slug', 'sales_crm_growth')->first();
        $this->assertSame(['cold-email-drafting', 'linkedin-outreach-specialist'], $agent->config['skills']);

        $enabledOnly = $this->actingAs($user, 'sanctum')->getJson('/api/skills?enabled=1')->assertOk()->json('data');
        $this->assertSame(['cold-email-drafting', 'linkedin-outreach-specialist'], array_column($enabledOnly, 'slug'));
    }

    public function test_pipeline_put_gates_autonomous_on_the_tenant_automation_level(): void
    {
        $tenant = $this->createTenant('manual');
        $user = $this->createUser($tenant);
        $this->installSalesPack($tenant);
        $this->actingAs($user, 'sanctum')->postJson('/api/skills/cold-email-drafting/enable')->assertOk();

        $denied = $this->actingAs($user, 'sanctum')->putJson('/api/skills/cold-email-drafting/pipeline', ['stage' => 'autonomous']);
        $denied->assertStatus(422);
        $this->assertStringContainsString('manual', $denied->json('message'));
        $this->assertSame('human_led', TenantSkill::forTenant($tenant->id)->value('autonomy_level'));

        $this->actingAs($user, 'sanctum')->putJson('/api/skills/cold-email-drafting/pipeline', ['stage' => 'assisted'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'assisted')
            ->assertJsonPath('tenant_skill.autonomy_level', 'assisted');

        $tenant->update(['automation_level' => 'assisted']);
        $this->actingAs($user, 'sanctum')->putJson('/api/skills/cold-email-drafting/pipeline', ['stage' => 'autonomous'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'autonomous');
        $this->assertSame('autonomous', TenantSkill::forTenant($tenant->id)->value('autonomy_level'));
    }

    public function test_pipeline_put_rejects_stages_off_the_ladder(): void
    {
        $tenant = $this->createTenant('autonomous');
        $user = $this->createUser($tenant);
        $this->installSalesPack($tenant);

        $this->actingAs($user, 'sanctum')->putJson('/api/skills/cold-email-drafting/pipeline', ['stage' => 'bogus'])->assertStatus(422);
        $this->actingAs($user, 'sanctum')->putJson('/api/skills/cold-email-drafting/pipeline', [])->assertStatus(422);

        // LinkedIn's ladder stops at assisted until Richard lands.
        $response = $this->actingAs($user, 'sanctum')->putJson('/api/skills/linkedin-outreach-specialist/pipeline', ['stage' => 'autonomous']);
        $response->assertStatus(422);
        $this->assertStringContainsString('human_led → assisted', $response->json('message'));

        // A pipeline change on a not-yet-enabled skill enables it first.
        $this->actingAs($user, 'sanctum')->putJson('/api/skills/linkedin-outreach-specialist/pipeline', ['stage' => 'assisted'])->assertOk();
        $row = TenantSkill::forTenant($tenant->id)->where('skill_slug', 'linkedin-outreach-specialist')->first();
        $this->assertTrue($row->enabled);
        $this->assertSame('assisted', $row->autonomy_level);
        $this->assertSame('pipeline', $row->state['installed_from']);
    }

    public function test_platform_skills_need_no_pack(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/skills/prospect-research-analysis/enable');

        $response->assertOk();
        $this->assertTrue($response->json('data.entitlement.entitled'));
        $this->assertFalse($response->json('data.entitlement.required'));
        $agent = Agent::where('tenant_id', $tenant->id)->where('slug', 'prism')->first();
        $this->assertNotNull($agent);
        $this->assertSame('core', $agent->type);
        $this->assertSame('prism', AgentWorkspace::where('tenant_id', $tenant->id)->value('slug'));
    }

    public function test_feedback_and_signals(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/skills/cold-email-drafting/feedback', ['sentiment' => 'positive', 'outcome' => 'first sequence approved'])
            ->assertOk()
            ->assertJsonPath('recorded', true);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/skills/cold-email-drafting/feedback', ['sentiment' => 'meh'])
            ->assertStatus(422);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/skills/no-such-skill/feedback', ['sentiment' => 'positive'])
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/skills/signals', ['type' => 'card_opened', 'slug' => 'cold-email-drafting'])
            ->assertStatus(202)
            ->assertJsonPath('accepted', true);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/skills/signals', ['type' => 'Bad Type!', 'slug' => 'cold-email-drafting'])
            ->assertStatus(422);

        $this->assertSame(1, DB::table('tenant_pack_signals')->where('tenant_id', $tenant->id)->where('signal_type', 'skill_feedback_positive')->count());
        $this->assertSame(1, DB::table('tenant_pack_signals')->where('tenant_id', $tenant->id)->where('signal_type', 'skill_card_opened')->count());
    }

    public function test_run_route_is_registered_for_the_runtime_stream(): void
    {
        $this->assertTrue(app('router')->has('skills.run'));
        $this->assertSame('api/skills/{slug}/run', app('router')->getRoutes()->getByName('skills.run')->uri());
    }
}

/** The first-release slugs, shared with the unit suite without cross-suite class loading. */
final class SkillRegistryTestSlugs
{
    public const FIRST_RELEASE = [
        'brand-voice-keeper',
        'cold-email-drafting',
        'customer-newsletter',
        'follow-up-drafting',
        'icp-definition',
        'inbox-triage-reply-classifier',
        'linkedin-outreach-specialist',
        'meeting-booking',
        'prospect-research-analysis',
    ];
}
