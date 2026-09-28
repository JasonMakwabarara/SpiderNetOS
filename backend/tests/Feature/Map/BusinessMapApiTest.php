<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Models\AgentRun;
use App\Models\BusinessProcess;
use App\Models\BusinessSystem;
use App\Models\Skill;
use App\Models\SkillRelation;
use App\Models\Tenant;
use App\Models\TenantSkill;
use App\Models\User;
use App\Services\Skills\SkillRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/map + /api/map/nodes/{id} (plan D6-C): the core's three brains,
 * the nine pillars in catalogue order, skill nodes grouped by map_node with
 * builds_on edges, system nodes for work no skill covers, node detail, tenant
 * isolation and the Postgres-safe id guard.
 */
class BusinessMapApiTest extends TestCase
{
    use RefreshDatabase;

    private const PILLARS = ['sales', 'deals', 'marketing', 'operations', 'intelligence', 'customer', 'back_office', 'people', 'founder'];

    private const NODE_KEYS = ['id', 'label', 'kind', 'status', 'owner_type', 'skills', 'brain_files', 'builds_on', 'runs', 'process_count', 'system_id'];

    protected function setUp(): void
    {
        parent::setUp();
        SkillRegistry::flush();
        $this->seedCatalogue();
    }

    public function test_map_shape_pillar_order_grouping_edges_and_statuses_for_a_skills_only_tenant(): void
    {
        $this->flags(['brain.enabled' => 'off']);
        [$tenant, $admin] = $this->tenantWithAdmin('Northbeam Books', 'Thandi Founder');

        TenantSkill::create(['tenant_id' => $tenant->id, 'skill_slug' => 'map-cold-email', 'enabled' => true, 'autonomy_level' => 'assisted']);
        TenantSkill::create(['tenant_id' => $tenant->id, 'skill_slug' => 'map-booking', 'enabled' => true, 'autonomy_level' => 'autonomous']);
        $this->agentRun($tenant, 'map-cold-email', 'succeeded', now()->subDays(2));
        $this->agentRun($tenant, 'map-linkedin-note', 'failed', now());
        $this->agentRun($tenant, 'map-cold-email', 'succeeded', now()->subDays(20));

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/map')->assertOk();
        $data = $response->json('data');

        // Core: business, owner, the three brains (Knowledge off → no readiness).
        $this->assertSame('Northbeam Books', $data['core']['name']);
        $this->assertSame('Thandi Founder', $data['core']['owner']['name']);
        $this->assertSame(['knowledge', 'operating', 'learning'], array_keys($data['core']['three_brains']));
        $this->assertSame(['pct' => null, 'files_filled' => 0, 'files_total' => 0, 'enabled' => false], $data['core']['three_brains']['knowledge']);
        $this->assertSame(['workspaces_active' => 0, 'runs_today' => 1], $data['core']['three_brains']['operating']);
        $this->assertSame(['outcomes_30d' => 0, 'experiments_open' => 0], $data['core']['three_brains']['learning']);

        // Nine pillars in catalogue order.
        $this->assertSame(self::PILLARS, array_column($data['pillars'], 'key'));
        $this->assertSame([10, 20, 30, 40, 50, 60, 70, 80, 90], array_column($data['pillars'], 'order'));
        $this->assertSame('Back office', $data['pillars'][6]['label']);
        foreach ($data['pillars'] as $pillar) {
            $this->assertSame(['key', 'label', 'order', 'status', 'nodes'], array_keys($pillar));
            foreach ($pillar['nodes'] as $node) {
                $this->assertSame(self::NODE_KEYS, array_keys($node));
            }
        }

        $sales = $this->pillar($data, 'sales');
        // Two skills share "Sales › Outreach writing" → one node; nodes sorted by label.
        $this->assertSame(['sales-linkedin-messaging', 'sales-outreach-writing'], array_column($sales['nodes'], 'id'));
        $outreach = $sales['nodes'][1];
        $this->assertSame('Outreach writing', $outreach['label']);
        $this->assertSame('skill', $outreach['kind']);
        $this->assertSame([
            ['slug' => 'map-cold-email', 'name' => 'Cold Email', 'enabled' => true, 'stage' => 'assisted'],
            ['slug' => 'map-follow-up', 'name' => 'Follow Up', 'enabled' => false, 'stage' => 'human_led'],
        ], $outreach['skills']);
        $this->assertSame('assisted', $outreach['status']);
        $this->assertSame('agent', $outreach['owner_type']);
        $this->assertSame([], $outreach['brain_files']);
        $this->assertSame(1, $outreach['runs']['count_7d']);
        $this->assertSame('succeeded', $outreach['runs']['last_status']);
        $this->assertSame(0, $outreach['process_count']);

        $linkedin = $sales['nodes'][0];
        $this->assertSame('missing', $linkedin['status']);
        $this->assertSame('founder', $linkedin['owner_type']);
        $this->assertSame('failed', $linkedin['runs']['last_status']);
        $this->assertSame(['sales-outreach-writing'], $linkedin['builds_on']);
        $this->assertSame([], $outreach['builds_on']); // follow-up → cold-email is inside the node
        $this->assertSame('assisted', $sales['status']);

        // Autonomous with the brain off → live; a builds_on edge across pillars.
        $booking = $this->pillar($data, 'deals')['nodes'][0];
        $this->assertSame(['deals-booking', 'live', 'agent'], [$booking['id'], $booking['status'], $booking['owner_type']]);
        $this->assertSame(['sales-outreach-writing'], $booking['builds_on']);
        $this->assertSame('live', $this->pillar($data, 'deals')['status']);

        // No systemization data: pillars without skills are empty and missing.
        foreach (['operations', 'intelligence', 'customer', 'back_office', 'people', 'founder'] as $key) {
            $this->assertSame([], $this->pillar($data, $key)['nodes'], $key);
            $this->assertSame('missing', $this->pillar($data, $key)['status'], $key);
        }

        // Deterministic.
        $this->assertSame($data, $this->actingAs($admin, 'sanctum')->getJson('/api/map')->json('data'));
    }

    public function test_systems_become_nodes_when_no_skill_covers_them_and_systemization_map_lists_skills(): void
    {
        $this->flags(['brain.enabled' => 'on']);
        [$tenant, $admin] = $this->tenantWithAdmin();
        $this->brainFile($tenant, 'notes/map-voice.md', "# Voice\n\nWarm, plain, direct.\n");

        $this->actingAs($admin, 'sanctum')->postJson('/api/systemization/bootstrap')->assertOk();
        $salesSystem = BusinessSystem::query()->where('tenant_id', $tenant->id)->where('function', 'sales')->firstOrFail();
        $opsSystem = BusinessSystem::query()->where('tenant_id', $tenant->id)->where('function', 'operations')->firstOrFail();

        $this->process($tenant, $salesSystem, 'Send call reminders', 'founder');
        $this->process($tenant, $salesSystem, 'Write the cold emails', 'agent', 'map-cold-email');
        $this->process($tenant, $opsSystem, 'Ship orders', 'team');

        $data = $this->actingAs($admin, 'sanctum')->getJson('/api/map')->assertOk()->json('data');

        $this->assertTrue($data['core']['three_brains']['knowledge']['enabled']);
        $this->assertIsInt($data['core']['three_brains']['knowledge']['pct']);

        $sales = $this->pillar($data, 'sales');
        $ids = array_column($sales['nodes'], 'id');
        // Skill nodes first, then the Sales system (it still has a process no skill does).
        $this->assertSame(['sales-linkedin-messaging', 'sales-outreach-writing', (string) $salesSystem->id], $ids);
        $outreach = $sales['nodes'][1];
        $this->assertSame(1, $outreach['process_count']);
        $this->assertSame('assisted', $outreach['status']); // agent-owned process, no runbook yet
        $this->assertSame([['path' => 'notes/map-missing.md', 'status' => 'missing'], ['path' => 'notes/map-voice.md', 'status' => 'ready']], $outreach['brain_files']);
        $system = $sales['nodes'][2];
        $this->assertSame(['system', 'Sales', 'human', 'founder', 1], [$system['kind'], $system['label'], $system['status'], $system['owner_type'], $system['process_count']]);
        $this->assertSame((string) $salesSystem->id, $system['system_id']);

        // Functions with no skill: every bootstrapped system is a node on its pillar.
        $this->assertSame([(string) $opsSystem->id], array_column($this->pillar($data, 'operations')['nodes'], 'id'));
        $this->assertSame(['human', 'team'], [$this->pillar($data, 'operations')['nodes'][0]['status'], $this->pillar($data, 'operations')['nodes'][0]['owner_type']]);
        $this->assertSame(['Management'], array_column($this->pillar($data, 'intelligence')['nodes'], 'label'));
        $this->assertSame(['Finance'], array_column($this->pillar($data, 'back_office')['nodes'], 'label'));
        $this->assertSame(['Recruitment'], array_column($this->pillar($data, 'people')['nodes'], 'label'));
        $this->assertSame('missing', $this->pillar($data, 'back_office')['nodes'][0]['status']);
        // Marketing is covered by a skill and has no uncovered process → no system node.
        $this->assertSame(['marketing-brand-voice'], array_column($this->pillar($data, 'marketing')['nodes'], 'id'));

        // GET /api/systemization/map gains skills[] per system (additive).
        $systems = collect($this->actingAs($admin, 'sanctum')->getJson('/api/systemization/map')->assertOk()->json('data.systems'))->keyBy('function');
        $this->assertSame(['map-booking', 'map-cold-email', 'map-follow-up', 'map-linkedin-note'], array_column($systems['sales']['skills'], 'slug'));
        $this->assertSame(['slug', 'name', 'pillar', 'node_id', 'enabled', 'stage'], array_keys($systems['sales']['skills'][0]));
        $this->assertSame('deals-booking', $systems['sales']['skills'][0]['node_id']);
        $this->assertSame(['map-brand-voice'], array_column($systems['marketing']['skills'], 'slug'));
        $this->assertSame([], $systems['finance']['skills']);
        $this->assertCount(2, $systems['sales']['processes']);
    }

    public function test_node_detail_returns_processes_last_ten_runs_and_brain_readiness(): void
    {
        $this->flags(['brain.enabled' => 'on']);
        [$tenant, $admin] = $this->tenantWithAdmin();
        $this->actingAs($admin, 'sanctum')->postJson('/api/systemization/bootstrap')->assertOk();
        $salesSystem = BusinessSystem::query()->where('tenant_id', $tenant->id)->where('function', 'sales')->firstOrFail();
        $linkedProcess = $this->process($tenant, $salesSystem, 'Write the cold emails', 'agent', 'map-follow-up');
        $this->process($tenant, $salesSystem, 'Send call reminders', 'founder');

        for ($i = 1; $i <= 12; $i++) {
            $this->agentRun($tenant, $i % 2 ? 'map-cold-email' : 'map-follow-up', 'succeeded', now()->subMinutes(100 - $i));
        }

        $node = $this->actingAs($admin, 'sanctum')->getJson('/api/map/nodes/sales-outreach-writing')->assertOk()->json('data');

        $this->assertSame([...self::NODE_KEYS, 'pillar', 'processes', 'runs_summary'], array_keys($node));
        $this->assertSame(['key' => 'sales', 'label' => 'Sales'], $node['pillar']);
        $this->assertSame(12, $node['runs_summary']['count_7d']);
        $this->assertSame([(string) $linkedProcess->id], array_column($node['processes'], 'id'));
        foreach (['id', 'system_id', 'name', 'owner_type', 'status', 'effort_size', 'skill_slug', 'has_published_sop'] as $key) {
            $this->assertArrayHasKey($key, $node['processes'][0], $key);
        }
        $this->assertCount(10, $node['runs']);
        $created = array_column($node['runs'], 'created_at');
        $sorted = $created;
        rsort($sorted);
        $this->assertSame($sorted, $created);
        $this->assertSame(['map-cold-email', 'map-follow-up'], collect($node['runs'])->pluck('skill_slug')->unique()->sort()->values()->all());
        $this->assertSame(['notes/map-missing.md', 'notes/map-voice.md'], array_column($node['brain_files'], 'path'));
        foreach (['title', 'status', 'required', 'sections', 'ask_prompt'] as $key) {
            $this->assertArrayHasKey($key, $node['brain_files'][0], $key);
        }
        $this->assertTrue($node['brain_files'][0]['required']);

        // A system node by uuid: its processes are the ones no skill does; no skill runs.
        $system = $this->actingAs($admin, 'sanctum')->getJson('/api/map/nodes/'.$salesSystem->id)->assertOk()->json('data');
        $this->assertSame(['Send call reminders'], array_column($system['processes'], 'name'));
        $this->assertSame([], $system['runs']);
        $this->assertSame('system', $system['kind']);
    }

    public function test_tenant_isolation(): void
    {
        $this->flags(['brain.enabled' => 'off']);
        [$tenantA, $adminA] = $this->tenantWithAdmin('Tenant A');
        [$tenantB, $adminB] = $this->tenantWithAdmin('Tenant B');

        $this->actingAs($adminB, 'sanctum')->postJson('/api/systemization/bootstrap')->assertOk();
        $systemB = BusinessSystem::query()->where('tenant_id', $tenantB->id)->where('function', 'finance')->firstOrFail();
        TenantSkill::create(['tenant_id' => $tenantB->id, 'skill_slug' => 'map-cold-email', 'enabled' => true, 'autonomy_level' => 'autonomous']);
        $this->agentRun($tenantB, 'map-cold-email', 'succeeded', now());

        $data = $this->actingAs($adminA, 'sanctum')->getJson('/api/map')->assertOk()->json('data');
        $this->assertSame('Tenant A', $data['core']['name']);
        $this->assertSame(0, $data['core']['three_brains']['operating']['runs_today']);
        $outreach = collect($this->pillar($data, 'sales')['nodes'])->firstWhere('id', 'sales-outreach-writing');
        $this->assertSame(['missing', false, 0, null], [$outreach['status'], $outreach['skills'][0]['enabled'], $outreach['runs']['count_7d'], $outreach['runs']['last_at']]);
        $this->assertSame([], $this->pillar($data, 'back_office')['nodes']);

        $this->actingAs($adminA, 'sanctum')->getJson('/api/map/nodes/'.$systemB->id)->assertNotFound();
        $this->actingAs($adminB, 'sanctum')->getJson('/api/map/nodes/'.$systemB->id)->assertOk();
        $this->assertSame('Tenant B', $this->actingAs($adminB, 'sanctum')->getJson('/api/map')->json('data.core.name'));
    }

    public function test_non_uuid_and_unknown_ids_are_404_without_touching_uuid_columns(): void
    {
        $this->flags(['brain.enabled' => 'off']);
        [, $admin] = $this->tenantWithAdmin();

        $this->getJson('/api/map')->assertUnauthorized();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = [$query->sql, $query->bindings];
        });

        $this->actingAs($admin, 'sanctum')->getJson('/api/map/nodes/not-a-uuid')->assertNotFound()->assertJson(['message' => 'Map node not found.']);
        foreach ($queries as [$sql, $bindings]) {
            $this->assertNotContains('not-a-uuid', $bindings, 'non-uuid id reached SQL: '.$sql);
        }

        $this->actingAs($admin, 'sanctum')->getJson('/api/map/nodes/'.Str::uuid())->assertNotFound();
        $this->actingAs($admin, 'sanctum')->getJson('/api/map/nodes/sales-outreach-writing')->assertOk();
    }

    // ------------------------------------------------------------------ //
    //  fixtures
    // ------------------------------------------------------------------ //

    /** Five catalogue rows independent of packages/skills, so the map shape is pinned here. */
    private function seedCatalogue(): void
    {
        $skill = function (string $slug, string $name, string $pillar, string $mapNode, array $brain = []): void {
            Skill::create([
                'slug' => $slug, 'version' => '1.0.0', 'name' => $name, 'pillar' => $pillar,
                'map_function' => Skill::PILLAR_FUNCTIONS[$pillar], 'map_node' => $mapNode,
                'runs_on' => 'growth', 'core_agent' => 'nexus',
                'card' => ['id' => $slug, 'display_name' => $name, 'pillar' => $pillar, 'map_node' => $mapNode,
                    'pipeline' => ['default_level' => 'human_led', 'ladder' => ['human_led', 'assisted', 'autonomous']], 'brain' => $brain],
            ]);
        };

        $skill('map-cold-email', 'Cold Email', 'sales', 'Sales › Outreach writing', ['requires' => [['path' => 'notes/map-voice.md']]]);
        $skill('map-follow-up', 'Follow Up', 'sales', 'Sales › Outreach writing', ['requires' => [['path' => 'notes/map-missing.md']], 'reads' => [['path' => 'notes/map-voice.md']]]);
        $skill('map-linkedin-note', 'LinkedIn Note', 'sales', 'Sales › LinkedIn messaging');
        $skill('map-booking', 'Booking', 'deals', 'Deals › Booking');
        $skill('map-brand-voice', 'Brand Voice', 'marketing', 'Marketing › Brand voice');

        SkillRelation::create(['from_slug' => 'map-linkedin-note', 'relation' => 'builds_on', 'to_slug' => 'map-cold-email', 'meta' => [], 'position' => 0]);
        SkillRelation::create(['from_slug' => 'map-booking', 'relation' => 'builds_on', 'to_slug' => 'map-follow-up', 'meta' => [], 'position' => 0]);
        SkillRelation::create(['from_slug' => 'map-booking', 'relation' => 'builds_on', 'to_slug' => null, 'to_ref' => 'brain:brand/voice.md', 'meta' => [], 'position' => 1]);
        // An edge inside one node is not an edge on the map.
        SkillRelation::create(['from_slug' => 'map-follow-up', 'relation' => 'builds_on', 'to_slug' => 'map-cold-email', 'meta' => [], 'position' => 0]);
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantWithAdmin(string $name = 'Map Co', string $userName = 'Founder'): array
    {
        $tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => $name, 'slug' => 'map-'.Str::lower(Str::random(8)),
            'status' => 'active', 'plan' => 'pro', 'automation_level' => 'assisted',
            'onboarding_completed_at' => now(), 'settings' => [],
        ]);
        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => $userName, 'email' => Str::lower(Str::random(10)).'@map.test',
            'password' => bcrypt('secret-password'), 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        return [$tenant, $admin];
    }

    /** @param array<string, string> $values */
    private function flags(array $values): void
    {
        config()->set('features', array_merge((array) config('features'), $values));
        Cache::flush();
    }

    private function agentRun(Tenant $tenant, string $slug, string $status, \DateTimeInterface $at): AgentRun
    {
        $run = AgentRun::create(['tenant_id' => $tenant->id, 'skill_slug' => $slug, 'status' => $status]);
        $run->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $run;
    }

    private function process(Tenant $tenant, BusinessSystem $system, string $name, string $owner, ?string $skillSlug = null): BusinessProcess
    {
        return BusinessProcess::create([
            'tenant_id' => $tenant->id, 'system_id' => $system->id, 'name' => $name, 'effort_size' => 2,
            'owner_type' => $owner, 'status' => $owner === 'agent' ? 'automated' : ($owner === 'team' ? 'delegated' : 'founder_owned'),
            'skill_slug' => $skillSlug,
        ]);
    }

    private function brainFile(Tenant $tenant, string $path, string $content): void
    {
        DB::table('brain_files')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'path' => $path, 'title' => $path,
            'content' => $content, 'frontmatter' => '{}', 'source' => 'human', 'managed' => false,
            'data_class' => 'internal', 'version' => 1, 'content_hash' => hash('sha256', $content),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function pillar(array $data, string $key): array
    {
        return collect($data['pillars'])->firstWhere('key', $key);
    }
}
