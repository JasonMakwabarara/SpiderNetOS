<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\AgentWorkspace;
use App\Models\BrainFile;
use App\Models\BrainFileVersion;
use App\Models\BrainProposal;
use App\Models\Skill;
use App\Models\SkillRelation;
use App\Models\Tenant;
use App\Models\TenantSkill;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PR 0 scaffolding: the three brain/runtime/catalogue migrations run on
 * sqlite :memory:, the models round-trip their casts and relationships, the
 * forTenant() scopes isolate tenants, and the two uniqueness rules hold
 * (brain path per tenant; skill + trigger_ref per tenant, NULL-tolerant).
 */
class AgentRuntimeSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(string $name = 'Brain Co'): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'plan' => 'growth',
            'automation_level' => 'assisted',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createAgent(Tenant $tenant, string $slug = 'sales_crm_growth'): Agent
    {
        return Agent::create([
            'tenant_id' => $tenant->id,
            'name' => 'Growth Agent',
            'slug' => $slug,
            'type' => 'dynamic',
            'status' => 'active',
            'capabilities' => ['cold_email'],
            'config' => ['pack_id' => 'sales-crm'],
        ]);
    }

    private function createWorkspace(Tenant $tenant, Agent $agent, string $slug = 'growth'): AgentWorkspace
    {
        return AgentWorkspace::create([
            'tenant_id' => $tenant->id,
            'agent_id' => $agent->id,
            'slug' => $slug,
            'drafts_root' => AgentWorkspace::defaultDraftsRoot($slug),
            'pinned_brain_paths' => ['brand/voice.md', 'offer/offer.md'],
            'scratch' => ['ideas.md' => '# Ideas'],
            'budget_daily_usd' => 2.0,
        ]);
    }

    private function createSkill(string $slug = 'cold-email-drafting'): Skill
    {
        return Skill::create([
            'slug' => $slug,
            'version' => '1.0.0',
            'name' => 'Cold Email Drafting',
            'pillar' => 'sales',
            'map_node' => 'Sales › Outreach writing',
            'runs_on' => 'growth',
            'core_agent' => 'nexus',
            'pack_id' => 'sales-crm',
            'card' => ['id' => $slug, 'tools' => ['brain.read', 'drafts.save'], 'run' => ['kind' => 'agent']],
            'prompt_md' => '# Task',
        ]);
    }

    public function test_workspace_run_steps_and_artifacts_round_trip(): void
    {
        $tenant = $this->createTenant();
        $agent = $this->createAgent($tenant);
        $workspace = $this->createWorkspace($tenant, $agent);
        $skill = $this->createSkill();

        $tenantSkill = TenantSkill::create([
            'tenant_id' => $tenant->id,
            'skill_slug' => $skill->slug,
            'agent_id' => $agent->id,
            'workspace_id' => $workspace->id,
            'enabled' => true,
            'autonomy_level' => TenantSkill::AUTONOMY_ASSISTED,
            'tool_overrides' => ['deny' => ['messages.send']],
            'enabled_at' => now(),
        ]);

        $run = AgentRun::create([
            'tenant_id' => $tenant->id,
            'workspace_id' => $workspace->id,
            'agent_id' => $agent->id,
            'skill_slug' => $skill->slug,
            'mode' => AgentRun::MODE_SINGLE_SHOT,
            'trigger_type' => AgentRun::TRIGGER_MANUAL,
            'status' => AgentRun::STATUS_RUNNING,
            'inputs' => ['campaign' => 'acme-q4', 'steps' => 3],
            'brain_snapshot' => ['brand/voice.md' => 3, 'offer/offer.md' => 1],
            'claimed_by' => 'worker-1',
            'lease_expires_at' => now()->addSeconds(300),
            'started_at' => now(),
        ]);

        AgentRunStep::create([
            'tenant_id' => $tenant->id, 'run_id' => $run->id, 'seq' => 1,
            'kind' => AgentRunStep::KIND_PROMPT, 'name' => 'SkillPromptBuilder', 'input' => ['chars' => 1200],
        ]);
        AgentRunStep::create([
            'tenant_id' => $tenant->id, 'run_id' => $run->id, 'seq' => 2,
            'kind' => AgentRunStep::KIND_TOOL_CALL, 'name' => 'drafts.save', 'output' => ['artifact_id' => 'x'],
            'tokens' => 350, 'cost_usd' => 0.0021, 'duration_ms' => 40,
        ]);

        $artifact = AgentArtifact::create([
            'tenant_id' => $tenant->id,
            'run_id' => $run->id,
            'workspace_id' => $workspace->id,
            'skill_slug' => $skill->slug,
            'kind' => AgentArtifact::KIND_DRAFT_SEQUENCE,
            'path' => 'workspaces/growth/drafts/acme-q4.md',
            'title' => 'Acme Q4 sequence',
            'content' => "## Step 1\n...",
            'meta' => ['steps' => 3, 'variants' => 2],
        ]);

        $run->update([
            'status' => AgentRun::STATUS_WAITING_APPROVAL,
            'outputs' => ['artifact_ids' => [$artifact->id], 'next_steps' => [['id' => 'followup', 'origin' => 'card', 'skill' => 'follow-up-drafting']]],
            'tokens' => 350,
            'cost_usd' => 0.0021,
        ]);
        $workspace->update(['status' => AgentWorkspace::STATUS_NEEDS_REVIEW, 'last_run_id' => $run->id, 'spent_today_usd' => 0.0021, 'spent_day' => now()->toDateString()]);

        $run = AgentRun::with(['workspace', 'agent', 'skill', 'steps', 'artifacts'])->findOrFail($run->id);
        $this->assertSame($workspace->id, $run->workspace->id);
        $this->assertSame($agent->id, $run->agent->id);
        $this->assertSame('Cold Email Drafting', $run->skill->name);
        $this->assertSame([1, 2], $run->steps->pluck('seq')->all());
        $this->assertSame('drafts.save', $run->steps[1]->name);
        $this->assertSame(['artifact_id' => 'x'], $run->steps[1]->output);
        $this->assertCount(1, $run->artifacts);
        $this->assertSame(['steps' => 3, 'variants' => 2], $run->artifacts[0]->meta);
        $this->assertSame(AgentArtifact::STATUS_DRAFT, $run->artifacts[0]->status);
        $this->assertSame('follow-up-drafting', $run->nextSteps()[0]['skill']);
        $this->assertTrue($run->isParked());
        $this->assertFalse($run->isTerminal());
        $this->assertSame(['brand/voice.md' => 3, 'offer/offer.md' => 1], $run->brain_snapshot);

        $workspace = AgentWorkspace::with(['agent', 'runs', 'lastRun', 'artifacts', 'tenantSkills'])->findOrFail($workspace->id);
        $this->assertSame(['brand/voice.md', 'offer/offer.md'], $workspace->pinned_brain_paths);
        $this->assertSame(['ideas.md' => '# Ideas'], $workspace->scratch);
        $this->assertSame('workspaces/growth/scratch', $workspace->scratchPath());
        $this->assertSame($run->id, $workspace->lastRun->id);
        $this->assertCount(1, $workspace->runs);
        $this->assertCount(1, $workspace->artifacts);
        $this->assertSame($tenantSkill->id, $workspace->tenantSkills[0]->id);
        $this->assertEqualsWithDelta(1.9979, $workspace->remainingBudgetUsd(), 0.0001);

        $this->assertSame(['deny' => ['messages.send']], $tenantSkill->fresh()->tool_overrides);
        $this->assertSame($skill->slug, $tenantSkill->skill->slug);
        $this->assertSame('agent', $skill->runKind());
        $this->assertSame('sales', $skill->mapFunction());
        $this->assertSame(['brain.read', 'drafts.save'], $skill->tools());
    }

    public function test_skill_relations_link_cards_and_external_refs(): void
    {
        $cold = $this->createSkill('cold-email-drafting');
        $this->createSkill('follow-up-drafting');

        SkillRelation::create(['from_slug' => $cold->slug, 'relation' => SkillRelation::HANDS_OFF_TO, 'to_slug' => 'follow-up-drafting', 'meta' => ['when' => 'day 3, no reply'], 'position' => 0]);
        SkillRelation::create(['from_slug' => $cold->slug, 'relation' => SkillRelation::HANDS_OFF_TO, 'to_ref' => 'richard', 'meta' => ['when' => 'channel switches to LinkedIn'], 'position' => 1]);
        SkillRelation::create(['from_slug' => $cold->slug, 'relation' => SkillRelation::BUILDS_ON, 'to_ref' => 'brain:brand/voice.md#tone', 'position' => 0]);
        SkillRelation::create(['from_slug' => $cold->slug, 'relation' => SkillRelation::REPLACES, 'to_ref' => 'role:SDR', 'meta' => ['what' => 'an SDR\'s core output', 'cost' => '$60-80k/yr', 'kind' => 'salary'], 'position' => 0]);

        $edges = $cold->edges()->get();
        $this->assertCount(4, $edges);

        $handoffs = $cold->edgesOf(SkillRelation::HANDS_OFF_TO)->get();
        $this->assertSame(['follow-up-drafting', null], $handoffs->pluck('to_slug')->all());
        $this->assertSame('follow-up-drafting', $handoffs[0]->to->slug);
        $this->assertTrue($handoffs[1]->isExternal());
        $this->assertSame('richard', $handoffs[1]->to_ref);

        $replaces = $cold->edgesOf(SkillRelation::REPLACES)->first();
        $this->assertSame('salary', $replaces->meta['kind']);

        // Deleting the card cascades its edges.
        $cold->delete();
        $this->assertSame(0, SkillRelation::where('from_slug', 'cold-email-drafting')->count());
    }

    public function test_brain_files_versions_and_proposals_round_trip(): void
    {
        $tenant = $this->createTenant();

        $file = BrainFile::create([
            'tenant_id' => $tenant->id,
            'path' => 'brand/voice.md',
            'title' => 'Brand voice',
            'content' => "## Tone\nWarm, direct.",
            'frontmatter' => ['tone' => 'warm', 'palette' => ['charge' => '#ff6600']],
            'source' => BrainFile::SOURCE_HUMAN,
            'data_class' => BrainFile::DATA_INTERNAL,
            'content_hash' => BrainFile::hashContent("## Tone\nWarm, direct."),
        ]);

        BrainFileVersion::create([
            'tenant_id' => $tenant->id, 'brain_file_id' => $file->id, 'version' => 1,
            'content' => $file->content, 'frontmatter' => $file->frontmatter, 'content_hash' => $file->content_hash,
            'source' => BrainFile::SOURCE_HUMAN, 'author_type' => BrainFileVersion::AUTHOR_USER, 'change_summary' => 'initial',
        ]);

        $proposal = BrainProposal::create([
            'tenant_id' => $tenant->id,
            'path' => 'brand/voice.md',
            'brain_file_id' => $file->id,
            'base_version' => 1,
            'proposed_content' => "## Tone\nWarm, direct, never salesy.",
            'rationale' => 'Three approved drafts were edited to remove hype words.',
            'proposed_by_type' => BrainProposal::BY_AGENT,
        ]);

        $file = BrainFile::with(['versions', 'proposals'])->findOrFail($file->id);
        $this->assertSame('warm', $file->frontmatter['tone']);
        $this->assertFalse($file->managed);
        $this->assertSame(1, $file->version);
        $this->assertFalse($file->isEmbedded());
        $this->assertSame('brand', $file->folder());
        $this->assertCount(1, $file->versions);
        $this->assertNotNull($file->versions[0]->created_at);
        $this->assertSame($file->id, $file->versions[0]->file->id);
        $this->assertCount(1, $file->proposals);
        $this->assertSame(BrainProposal::STATUS_PENDING, $file->proposals[0]->status);
        $this->assertTrue($proposal->isOpen());
        $this->assertSame(1, BrainProposal::forTenant((string) $tenant->id)->open()->count());

        // Folder scope.
        BrainFile::create(['tenant_id' => $tenant->id, 'path' => 'people/user.md', 'content' => '', 'content_hash' => BrainFile::hashContent('')]);
        BrainFile::create(['tenant_id' => $tenant->id, 'path' => 'people/team/ops.md', 'content' => '', 'content_hash' => BrainFile::hashContent('')]);
        $this->assertSame(['people/team/ops.md', 'people/user.md'], BrainFile::forTenant((string) $tenant->id)->under('people')->orderBy('path')->pluck('path')->all());
        $this->assertSame(['people/team/ops.md'], BrainFile::forTenant((string) $tenant->id)->under('people/team/')->pluck('path')->all());
    }

    public function test_for_tenant_scopes_isolate_tenants(): void
    {
        $a = $this->createTenant('Tenant A');
        $b = $this->createTenant('Tenant B');

        foreach ([$a, $b] as $tenant) {
            $agent = $this->createAgent($tenant);
            $workspace = $this->createWorkspace($tenant, $agent);
            $skill = Skill::firstOrCreate(['slug' => 'cold-email-drafting'], ['version' => '1.0.0', 'name' => 'Cold Email Drafting', 'pillar' => 'sales']);
            TenantSkill::create(['tenant_id' => $tenant->id, 'skill_slug' => $skill->slug, 'agent_id' => $agent->id, 'workspace_id' => $workspace->id, 'enabled' => true]);
            $run = AgentRun::create(['tenant_id' => $tenant->id, 'workspace_id' => $workspace->id, 'skill_slug' => $skill->slug]);
            AgentRunStep::create(['tenant_id' => $tenant->id, 'run_id' => $run->id, 'seq' => 1, 'kind' => AgentRunStep::KIND_NOTE]);
            AgentArtifact::create(['tenant_id' => $tenant->id, 'run_id' => $run->id, 'kind' => AgentArtifact::KIND_NOTE]);
            $file = BrainFile::create(['tenant_id' => $tenant->id, 'path' => 'business/profile.md', 'content' => $tenant->name, 'content_hash' => BrainFile::hashContent($tenant->name)]);
            BrainFileVersion::create(['tenant_id' => $tenant->id, 'brain_file_id' => $file->id, 'version' => 1, 'content' => $tenant->name, 'content_hash' => $file->content_hash]);
            BrainProposal::create(['tenant_id' => $tenant->id, 'path' => 'business/profile.md', 'brain_file_id' => $file->id, 'base_version' => 1, 'proposed_content' => 'x']);
        }

        foreach ([AgentWorkspace::class, TenantSkill::class, AgentRun::class, AgentRunStep::class, AgentArtifact::class, BrainFile::class, BrainFileVersion::class, BrainProposal::class] as $model) {
            $this->assertSame(2, $model::count(), $model);
            $this->assertSame(1, $model::forTenant((string) $a->id)->count(), $model.' forTenant(a)');
            $this->assertSame(1, $model::forTenant((string) $b->id)->count(), $model.' forTenant(b)');
        }

        // Same path in two brains is fine; the same path twice in one brain is not.
        $this->assertSame(2, BrainFile::where('path', 'business/profile.md')->count());
        $this->expectException(QueryException::class);
        BrainFile::create(['tenant_id' => $a->id, 'path' => 'business/profile.md', 'content' => '', 'content_hash' => BrainFile::hashContent('')]);
    }

    public function test_trigger_ref_is_unique_per_tenant_and_skill_but_null_is_unlimited(): void
    {
        $tenant = $this->createTenant();
        $other = $this->createTenant('Other');
        $agent = $this->createAgent($tenant);
        $workspace = $this->createWorkspace($tenant, $agent);
        $this->createSkill();

        $base = ['tenant_id' => $tenant->id, 'workspace_id' => $workspace->id, 'skill_slug' => 'cold-email-drafting'];

        // Manual/API runs carry no trigger_ref and may repeat freely.
        AgentRun::create($base);
        AgentRun::create($base);

        // Event/cron replays coalesce on trigger_ref...
        AgentRun::create($base + ['trigger_type' => AgentRun::TRIGGER_EVENT, 'trigger_ref' => 'evt-123']);
        // ...per skill...
        AgentRun::create(['tenant_id' => $tenant->id, 'skill_slug' => 'follow-up-drafting', 'trigger_type' => AgentRun::TRIGGER_EVENT, 'trigger_ref' => 'evt-123']);
        // ...and per tenant.
        AgentRun::create(['tenant_id' => $other->id, 'skill_slug' => 'cold-email-drafting', 'trigger_type' => AgentRun::TRIGGER_EVENT, 'trigger_ref' => 'evt-123']);

        $this->assertSame(5, AgentRun::count());

        $this->expectException(QueryException::class);
        AgentRun::create($base + ['trigger_type' => AgentRun::TRIGGER_EVENT, 'trigger_ref' => 'evt-123']);
    }

    public function test_stale_scope_finds_only_active_runs_past_their_lease(): void
    {
        $tenant = $this->createTenant();
        $agent = $this->createAgent($tenant);
        $workspace = $this->createWorkspace($tenant, $agent);
        $base = ['tenant_id' => $tenant->id, 'workspace_id' => $workspace->id, 'skill_slug' => 'cold-email-drafting'];

        $stale = AgentRun::create($base + ['status' => AgentRun::STATUS_RUNNING, 'lease_expires_at' => now()->subMinute()]);
        AgentRun::create($base + ['status' => AgentRun::STATUS_RUNNING, 'lease_expires_at' => now()->addMinutes(5)]);
        AgentRun::create($base + ['status' => AgentRun::STATUS_WAITING_APPROVAL, 'lease_expires_at' => now()->subMinute()]);
        AgentRun::create($base + ['status' => AgentRun::STATUS_QUEUED]);

        $this->assertSame([$stale->id], AgentRun::stale()->pluck('id')->all());
        $this->assertTrue($stale->leaseExpired());
        $this->assertSame(2, AgentRun::forTenant((string) $tenant->id)->active()->count());
        $this->assertSame(1, AgentRun::forTenant((string) $tenant->id)->parked()->count());
    }
}
