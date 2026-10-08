<?php

declare(strict_types=1);

namespace Tests\Feature\Founder;

use App\Jobs\FounderBriefJob;
use App\Jobs\GenerateDailyBriefJob;
use App\Models\Agent;
use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\AgentWorkspace;
use App\Models\AwarenessItem;
use App\Models\BrainFile;
use App\Models\ConversationMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Founder\FounderBriefService;
use App\Services\Notifications\NotificationBundler;
use App\Services\Notifications\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Needs-You Today (plan D8 #3): ranking, the 7-item cap, overnight, GET /api/today, the brain file. */
class FounderBriefServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Brief Co', 'slug' => 'brief-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(), 'settings' => ['timezone' => 'Africa/Nairobi'],
        ]);
        $this->admin = User::create([
            'name' => 'Owner', 'email' => 'owner-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);
    }

    private function approval(array $context, int $hoursAgo = 2, string $reason = 'Send the Acme sequence'): string
    {
        $id = (string) Str::uuid();
        DB::table('approvals')->insert([
            'id' => $id, 'tenant_id' => $this->tenant->id, 'requester_id' => (string) $this->admin->id,
            'approval_type' => 'artifact', 'resource_type' => 'agent_artifact', 'resource_id' => (string) Str::uuid(),
            'reason' => $reason, 'context' => json_encode($context), 'status' => 'pending',
            'requested_at' => now()->subHours($hoursAgo), 'created_at' => now()->subHours($hoursAgo), 'updated_at' => now()->subHours($hoursAgo),
        ]);

        return $id;
    }

    private function makeRun(string $status, array $attrs = []): AgentRun
    {
        return AgentRun::create(array_merge([
            'tenant_id' => $this->tenant->id, 'skill_slug' => 'cold-email-drafting', 'status' => $status,
        ], $attrs));
    }

    public function test_items_are_ranked_deterministically_and_capped_at_seven(): void
    {
        $money = $this->approval(['amount' => 1200, 'title' => 'Pay the Q3 invoice'], 30);
        $this->approval([], 1);
        $blocked = $this->makeRun('blocked', ['questions' => [['path' => 'offer/offer.md', 'section' => 'Pricing', 'question' => 'How is it priced?']]]);
        AwarenessItem::create(['tenant_id' => $this->tenant->id, 'source' => 'anomaly', 'title' => 'Bounce rate above 5%', 'severity' => 'critical', 'status' => 'open']);

        // conversations.lead_id → leads and conversation_messages.conversation_id → conversations are real FKs.
        $leadId = (string) Str::uuid();
        DB::table('leads')->insert(['id' => $leadId, 'tenant_id' => $this->tenant->id, 'created_at' => now(), 'updated_at' => now()]);
        $conversationId = (string) Str::uuid();
        DB::table('conversations')->insert([
            'id' => $conversationId, 'tenant_id' => $this->tenant->id, 'lead_id' => $leadId, 'channel' => 'manual_dm',
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (range(1, 3) as $i) {
            // created_at is not fillable on ConversationMessage; insert directly so the drafts are two days old.
            DB::table('conversation_messages')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'conversation_id' => $conversationId, 'direction' => 'out',
                'body' => "Draft {$i}", 'status' => 'draft', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
            ]);
        }
        $this->assertSame(3, ConversationMessage::forTenant((string) $this->tenant->id)->where('status', 'draft')->count());
        foreach (range(1, 3) as $i) {
            $this->approval(['note' => "extra {$i}"], $i);
        }

        $brief = app(FounderBriefService::class)->compose((string) $this->tenant->id);

        $this->assertCount(7, $brief['items']);
        $this->assertSame(FounderBriefService::MAX_ITEMS, count($brief['items']));
        $this->assertSame($money, $brief['items'][0]['id'], 'money at stake + age ranks first');
        $this->assertSame('approval', $brief['items'][0]['kind']);
        $this->assertSame(1200.0, $brief['items'][0]['money']);
        $this->assertSame('Review', $brief['items'][0]['action']['label']);
        $this->assertStringContainsString('30h', $brief['items'][0]['age']);

        $kinds = array_column($brief['items'], 'kind');
        $this->assertSame(5, count(array_keys($kinds, 'approval', true)));
        $this->assertContains('awareness', $kinds);
        $this->assertContains('blocked_run', $kinds);
        $this->assertNotContains('stale_draft', $kinds, 'stale drafts rank below approvals, awareness and blocked runs and fall off the top 7');
        $this->assertNotContains('brain_gap', $kinds);
        // 5 approvals + 1 blocked run + 1 awareness + 1 grouped stale draft + the top brain gap (BrainGapAnalyzer present)
        $this->assertSame(9, $brief['counts']['total_candidates']);
        $this->assertSame(1, $brief['counts']['brain_gap']);
        $this->assertSame(1, $brief['counts']['stale_draft']);

        $blockedItem = collect($brief['items'])->firstWhere('kind', 'blocked_run');
        $this->assertSame('How is it priced?', $blockedItem['detail']);
        $this->assertSame('/agents/runs/'.$blocked->id, $blockedItem['action']['path']);

        // Deterministic: same input, same order.
        $again = app(FounderBriefService::class)->compose((string) $this->tenant->id);
        $this->assertSame(array_column($brief['items'], 'id'), array_column($again['items'], 'id'));
        $this->assertSame('Africa/Nairobi', $brief['timezone']);
    }

    public function test_overnight_lists_runs_finished_since_yesterday_evening_and_new_artifacts(): void
    {
        $agent = Agent::create(['tenant_id' => $this->tenant->id, 'name' => 'Growth', 'slug' => 'growth', 'type' => 'dynamic', 'status' => 'active', 'capabilities' => [], 'config' => []]);
        $workspace = AgentWorkspace::create(['tenant_id' => $this->tenant->id, 'agent_id' => $agent->id, 'slug' => 'growth', 'drafts_root' => 'workspaces/growth/drafts']);

        $recent = $this->makeRun('succeeded', ['workspace_id' => $workspace->id, 'finished_at' => now()->subHours(3), 'outputs' => ['next_steps' => [['id' => 'a', 'label' => 'Draft follow-ups']]]]);
        $this->makeRun('succeeded', ['workspace_id' => $workspace->id, 'finished_at' => now()->subDays(3)]);
        $this->makeRun('running', ['workspace_id' => $workspace->id]);
        AgentArtifact::create(['tenant_id' => $this->tenant->id, 'run_id' => $recent->id, 'skill_slug' => 'cold-email-drafting', 'kind' => 'draft_sequence', 'title' => 'Acme sequence', 'status' => 'submitted']);

        $brief = app(FounderBriefService::class)->compose((string) $this->tenant->id);

        $this->assertCount(2, $brief['overnight']);
        $kinds = array_column($brief['overnight'], 'kind');
        $this->assertContains('run', $kinds);
        $this->assertContains('artifact', $kinds);
        $runEntry = collect($brief['overnight'])->firstWhere('kind', 'run');
        $this->assertSame($recent->id, $runEntry['id']);
        $this->assertSame(1, $runEntry['artifacts']);
        $this->assertSame(1, $runEntry['next_steps']);
        // Nothing needs the founder except (at most) the top brain gap of an empty brain.
        $this->assertSame([], array_values(array_filter(array_column($brief['items'], 'kind'), fn (string $k) => $k !== 'brain_gap')));
        $this->assertStringContainsString('## Overnight', $brief['markdown']);
        $this->assertStringContainsString('Cold Email Drafting finished', $brief['markdown']);
    }

    public function test_brief_is_filed_in_the_brain_and_served_by_get_today(): void
    {
        $this->approval(['amount' => 50], 4);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/today');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['date', 'tenant_id', 'timezone', 'generated_at', 'items', 'overnight', 'one_more_question', 'counts', 'path']])
            ->assertJsonPath('data.items.0.kind', 'approval')
            ->assertJsonPath('data.items.0.rank', 1)
            ->assertJsonStructure(['data' => ['items' => [['id', 'kind', 'title', 'detail', 'action' => ['label', 'path'], 'age', 'money']]]]);
        $this->assertArrayNotHasKey('markdown', $response->json('data'));

        $date = $response->json('data.date');
        $this->assertSame('reports/daily/'.$date.'.md', $response->json('data.path'));

        $file = BrainFile::forTenant((string) $this->tenant->id)->where('path', 'reports/daily/'.$date.'.md')->first();
        $this->assertNotNull($file, 'the brief is written to the Knowledge brain');
        $this->assertSame('agent', $file->source);
        $this->assertStringContainsString('# Needs-You Today — '.$date, $file->content);
        $this->assertStringContainsString('Approve:', $file->content);
        $this->assertSame(1, $file->version);

        // Re-composing the same day with the same content keeps the version; a change bumps it.
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/today')->assertOk();
        $this->assertSame(1, $file->refresh()->version);
        $this->approval(['amount' => 9000], 1);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/today')->assertOk();
        $this->assertSame(2, $file->refresh()->version);
        $this->assertDatabaseHas('brain_file_versions', ['brain_file_id' => $file->id, 'version' => 2]);
    }

    public function test_jobs_compose_and_deliver_inside_the_morning_window(): void
    {
        $this->approval(['amount' => 10], 1);

        $this->mock(WebPushService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->once()->withArgs(fn (string $userId, array $payload) => $payload['event_type'] === 'brief_ready' && str_contains($payload['title'], 'Needs-You Today'))->andReturn(1);
        });

        // Nairobi is UTC+3: 04:00 UTC = 07:00 local.
        Carbon::setTestNow(Carbon::parse('2026-09-16 04:00:00', 'UTC'));
        $this->assertTrue(FounderBriefJob::dueNow($this->tenant));
        $this->assertTrue(FounderBriefJob::dueNow($this->tenant, Carbon::parse('2026-09-16 03:56:00', 'UTC')));
        $this->assertFalse(FounderBriefJob::dueNow($this->tenant, Carbon::parse('2026-09-16 05:00:00', 'UTC')));
        $this->assertFalse(FounderBriefJob::dueNow($this->tenant, Carbon::parse('2026-09-16 03:30:00', 'UTC')));

        (new FounderBriefJob)->handle(app(FounderBriefService::class), app(NotificationBundler::class));
        // Second sweep in the same window does not re-send (the mock's once() would fail otherwise).
        (new FounderBriefJob)->handle(app(FounderBriefService::class), app(NotificationBundler::class));

        (new GenerateDailyBriefJob('2026-09-16'))->handle(app(FounderBriefService::class));
        $this->assertNotNull(BrainFile::forTenant((string) $this->tenant->id)->where('path', 'reports/daily/2026-09-16.md')->first());

        Carbon::setTestNow();
    }

    public function test_brief_today_command_prints_the_brief(): void
    {
        $this->approval(['amount' => 250, 'title' => 'Pay Globex'], 2);

        $this->artisan('brief:today', ['tenant' => $this->tenant->slug])
            ->expectsOutputToContain('Needs-You Today')
            ->expectsOutputToContain('Pay Globex')
            ->assertExitCode(0);

        $this->artisan('brief:today', ['tenant' => 'nope'])->assertExitCode(1);
    }
}
