<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Agents\GodsEyeSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * God's Eye (plan D6 §8): the live board of every agent at once, grouped by
 * character rather than by run.
 */
class GodsEyeSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Wall Co', 'slug' => 'wall-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
        $this->user = User::create([
            'name' => 'Jason', 'email' => 'j-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        $this->flags(['cockpit.gods_eye' => 'on']);
    }

    private function flags(array $values): void
    {
        config()->set('features', array_merge((array) config('features'), $values));
        Cache::flush();
    }

    private function enable(string $slug): void
    {
        DB::table('tenant_skills')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'skill_slug' => $slug,
            'enabled' => true, 'autonomy_level' => 'human_led', 'tool_overrides' => '{}', 'state' => '{}',
            'clean_drafts_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function agentRun(string $slug, string $status, ?Carbon $at = null, float $cost = 0.02, array $questions = []): string
    {
        $id = (string) Str::uuid();
        $at ??= now()->subHour();
        DB::table('agent_runs')->insert([
            'id' => $id, 'tenant_id' => $this->tenant->id, 'skill_slug' => $slug,
            'mode' => 'single_shot', 'trigger_type' => 'manual', 'status' => $status,
            'inputs' => '{}', 'outputs' => '{}', 'state' => '{}', 'questions' => json_encode($questions),
            'tokens' => 400, 'cost_usd' => $cost, 'created_at' => $at, 'updated_at' => $at,
        ]);

        return $id;
    }

    private function snapshot(): array
    {
        return app(GodsEyeSnapshot::class)->forTenant((string) $this->tenant->id);
    }

    private function character(array $snapshot, string $slug): array
    {
        return collect($snapshot['characters'])->firstWhere('slug', $slug);
    }

    // ------------------------------------------------------------------ //

    public function test_the_board_is_always_the_six_characters_even_when_nothing_is_on(): void
    {
        $snapshot = $this->snapshot();

        $this->assertSame(GodsEyeSnapshot::CHARACTERS, array_column($snapshot['characters'], 'slug'));

        // An empty column is a finding, not something to hide: "Sentinel has
        // nothing switched on" is exactly what the owner needs to see.
        foreach ($snapshot['characters'] as $character) {
            $this->assertSame('off', $character['state']);
            $this->assertSame([], $character['skills']);
        }

        $this->assertSame(0, $snapshot['totals']['runs']);
        $this->assertSame(6, $snapshot['totals']['characters_idle']);
        $this->assertFalse($snapshot['breaker']['paused']);
    }

    public function test_skills_land_under_the_character_they_report_to(): void
    {
        $this->enable('cold-email-drafting');       // nexus
        $this->enable('brand-voice-keeper');        // hannah
        $this->enable('prospect-research-analysis');

        $snapshot = $this->snapshot();

        $nexus = $this->character($snapshot, 'nexus');
        $this->assertContains('cold-email-drafting', array_column($nexus['skills'], 'slug'));

        $hannah = $this->character($snapshot, 'hannah');
        $this->assertContains('brand-voice-keeper', array_column($hannah['skills'], 'slug'));

        // Every enabled skill appears exactly once across the whole board.
        $allSlugs = collect($snapshot['characters'])->flatMap(fn (array $c): array => array_column($c['skills'], 'slug'));
        $this->assertSame($allSlugs->unique()->count(), $allSlugs->count());
        $this->assertCount(3, $allSlugs);
    }

    public function test_a_character_reports_the_state_of_its_busiest_skill(): void
    {
        $this->enable('cold-email-drafting');
        $this->enable('follow-up-drafting');

        // Idle: enabled, nothing ran.
        $this->assertSame('idle', $this->character($this->snapshot(), 'nexus')['state']);

        // Waiting beats idle.
        $this->agentRun('follow-up-drafting', 'waiting_input');
        $this->assertSame('waiting', $this->character($this->snapshot(), 'nexus')['state']);

        // Working beats waiting: something is actually on the wire.
        $this->agentRun('cold-email-drafting', 'running');
        $this->assertSame('working', $this->character($this->snapshot(), 'nexus')['state']);

        $nexus = $this->character($this->snapshot(), 'nexus');
        // The busiest skill sorts to the top of its column.
        $this->assertSame('cold-email-drafting', $nexus['skills'][0]['slug']);
        $this->assertSame([1, 1], [$nexus['active'], $nexus['waiting']]);
    }

    public function test_a_skill_that_only_ever_fails_is_shown_as_failing(): void
    {
        $this->enable('cold-email-drafting');
        $this->agentRun('cold-email-drafting', 'failed');
        $this->agentRun('cold-email-drafting', 'failed');

        $nexus = $this->character($this->snapshot(), 'nexus');
        $this->assertSame('failing', $nexus['state']);
        $this->assertSame('failing', $nexus['skills'][0]['state']);
        $this->assertSame(2, $nexus['failed']);

        // One success and it is no longer "failing" — it is a skill with a bad run.
        $this->agentRun('cold-email-drafting', 'succeeded');
        $this->assertSame('idle', $this->character($this->snapshot(), 'nexus')['state']);
    }

    public function test_the_window_is_the_last_day_not_all_of_history(): void
    {
        $this->enable('cold-email-drafting');
        $this->agentRun('cold-email-drafting', 'succeeded', now()->subHours(2));
        $this->agentRun('cold-email-drafting', 'succeeded', now()->subHours(GodsEyeSnapshot::WINDOW_HOURS + 2));

        $snapshot = $this->snapshot();
        $this->assertSame(1, $snapshot['totals']['runs']);
        $this->assertSame(GodsEyeSnapshot::WINDOW_HOURS, $snapshot['window_hours']);
    }

    public function test_the_live_lane_shows_what_is_on_the_wire_and_needs_you_shows_what_stopped(): void
    {
        $this->enable('cold-email-drafting');
        $running = $this->agentRun('cold-email-drafting', 'running');
        $blocked = $this->agentRun('cold-email-drafting', 'waiting_input', now()->subHours(3), 0.02, [
            ['id' => 'voice', 'question' => 'What tone should this sound like?'],
        ]);
        $this->agentRun('cold-email-drafting', 'succeeded');

        $snapshot = $this->snapshot();

        $this->assertSame([$running], array_column($snapshot['live'], 'id'));
        $this->assertSame('Cold Email Drafting', $snapshot['live'][0]['display_name']);

        $this->assertSame([$blocked], array_column($snapshot['needs_you'], 'id'));
        $this->assertSame('What tone should this sound like?', $snapshot['needs_you'][0]['question']);
        $this->assertSame('/agents/runs/'.$blocked, $snapshot['needs_you'][0]['path']);
    }

    public function test_a_paused_breaker_is_on_the_wall_so_idleness_is_never_mistaken_for_quiet(): void
    {
        $this->enable('cold-email-drafting');

        DB::table('tenant_agent_states')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'scope' => 'tenant', 'scope_id' => null, 'state' => 'paused',
            'reason' => 'Owner pressed stop', 'tripped_by' => 'user',
            'tripped_at' => now(), 'meta' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $breaker = $this->snapshot()['breaker'];
        $this->assertTrue($breaker['paused']);
        $this->assertSame('tenant', $breaker['scopes'][0]['scope']);
        $this->assertSame('Owner pressed stop', $breaker['scopes'][0]['reason']);
    }

    public function test_a_resumed_breaker_is_not_reported_as_paused(): void
    {
        DB::table('tenant_agent_states')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'scope' => 'tenant', 'scope_id' => null, 'state' => 'running',
            'reason' => 'Resumed', 'tripped_by' => 'user',
            'tripped_at' => now()->subHour(), 'resumed_at' => now(), 'meta' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $breaker = $this->snapshot()['breaker'];
        $this->assertFalse($breaker['paused']);
        $this->assertSame([], $breaker['scopes']);
    }

    // ------------------------------------------------------------------ //
    //  API
    // ------------------------------------------------------------------ //

    public function test_the_snapshot_endpoint_is_flag_gated_and_tenant_scoped(): void
    {
        $this->enable('cold-email-drafting');
        $this->agentRun('cold-email-drafting', 'running');

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/godseye/snapshot')->assertOk();
        $this->assertSame(GodsEyeSnapshot::CHARACTERS, array_column($response->json('data.characters'), 'slug'));
        $this->assertSame(1, $response->json('data.totals.active'));

        $this->flags(['cockpit.gods_eye' => 'off']);
        $this->actingAs($this->user, 'sanctum')->getJson('/api/godseye/snapshot')
            ->assertForbidden()->assertJsonPath('reason', 'gods_eye.disabled');
        $this->flags(['cockpit.gods_eye' => 'on']);

        $other = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Other Co', 'slug' => 'other-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
        $otherUser = User::create([
            'name' => 'Other', 'email' => 'o-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $other->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        $this->actingAs($otherUser, 'sanctum')->getJson('/api/godseye/snapshot')
            ->assertOk()->assertJsonPath('data.totals.runs', 0)->assertJsonPath('data.live', []);
    }

    public function test_the_snapshot_requires_authentication(): void
    {
        $this->getJson('/api/godseye/snapshot')->assertUnauthorized();
    }
}
