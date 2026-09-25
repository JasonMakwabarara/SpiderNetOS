<?php

declare(strict_types=1);

namespace Tests\Feature\Breaker;

use App\Models\Agent;
use App\Models\Tenant;
use App\Models\TenantAgentState;
use App\Models\TenantSkill;
use App\Models\User;
use App\Services\Agents\AgentCircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** AgentCircuitBreaker (plan D8 #6): scopes, demotion semantics, tripwires, the /agents/breaker endpoint. */
class AgentCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private AgentCircuitBreaker $breaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Breaker Co', 'slug' => 'breaker-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
        $this->admin = User::create([
            'name' => 'Owner', 'email' => 'owner-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);
        $this->breaker = app(AgentCircuitBreaker::class);
    }

    public function test_pause_everything_blocks_every_call_and_resume_clears_it(): void
    {
        $t = (string) $this->tenant->id;
        $this->assertNull($this->breaker->isPaused($t, null, 'cold-email-drafting', 'read'));

        $state = $this->breaker->pause($t, 'tenant', null, 'Founder on holiday');

        $this->assertSame('paused', $state->state);
        $this->assertStringContainsString('Founder on holiday', (string) $this->breaker->isPaused($t, 'any-agent', 'cold-email-drafting', 'read'));
        $this->assertNotNull($this->breaker->isPaused($t));

        $this->breaker->resume($t, 'tenant', null);

        $this->assertNull($this->breaker->isPaused($t, null, 'cold-email-drafting', 'send'));
        $this->assertSame('running', TenantAgentState::forTenant($t)->where('scope', 'tenant')->first()->state);
        $this->assertNotNull(TenantAgentState::forTenant($t)->where('scope', 'tenant')->first()->resumed_at);
    }

    public function test_stop_sends_keeps_drafting_and_agent_scope_matches_id_or_slug(): void
    {
        $t = (string) $this->tenant->id;
        $agent = Agent::create([
            'tenant_id' => $t, 'name' => 'Richard', 'slug' => 'richard', 'type' => 'dynamic', 'status' => 'active',
            'capabilities' => [], 'config' => [],
        ]);

        $this->breaker->pause($t, 'tool_risk', 'send', 'Stop sends but keep drafting');

        $this->assertNull($this->breaker->isPaused($t, null, 'cold-email-drafting', 'read'));
        $this->assertNull($this->breaker->isPaused($t, null, 'cold-email-drafting', 'write'));
        $this->assertNotNull($this->breaker->isPaused($t, null, 'cold-email-drafting', 'send'));
        $this->assertNotNull($this->breaker->isPaused($t, null, 'cold-email-drafting', 'irreversible'), 'a send pause also blocks irreversible tools');

        $this->breaker->pause($t, 'agent', 'richard', 'Pause Richard');

        $this->assertNotNull($this->breaker->isPaused($t, (string) $agent->id, null, 'read'), 'agent id resolves to its slug');
        $this->assertNotNull($this->breaker->isPaused($t, 'richard', null, 'read'));
        $this->assertNull($this->breaker->isPaused($t, 'growth', null, 'read'));
    }

    public function test_demote_blocks_only_send_and_drops_the_autonomy_rung_then_restores_it(): void
    {
        $t = (string) $this->tenant->id;
        TenantSkill::create(['tenant_id' => $t, 'skill_slug' => 'cold-email-drafting', 'enabled' => true, 'autonomy_level' => 'autonomous']);

        $state = $this->breaker->demote($t, 'skill', 'cold-email-drafting', 'validator rejects', 'tripwire', now()->addHours(12));

        $this->assertSame('demoted', $state->state);
        $this->assertSame('tripwire', $state->tripped_by);
        $this->assertSame('assisted', TenantSkill::forTenant($t)->where('skill_slug', 'cold-email-drafting')->first()->autonomy_level);
        $this->assertNull($this->breaker->isPaused($t, null, 'cold-email-drafting', 'write'), 'demoted keeps drafting');
        $this->assertNotNull($this->breaker->isPaused($t, null, 'cold-email-drafting', 'send'));
        $this->assertNull($this->breaker->isPaused($t, null, 'other-skill', 'send'));

        $this->breaker->resume($t, 'skill', 'cold-email-drafting');

        $this->assertSame('autonomous', TenantSkill::forTenant($t)->where('skill_slug', 'cold-email-drafting')->first()->autonomy_level);
        $this->assertNull($this->breaker->isPaused($t, null, 'cold-email-drafting', 'send'));
    }

    public function test_expired_resume_at_auto_resumes(): void
    {
        $t = (string) $this->tenant->id;
        $this->breaker->pause($t, 'skill', 'x', 'temporary', 'human', now()->subMinute());

        $this->assertNull($this->breaker->isPaused($t, null, 'x', 'read'));
        $this->assertSame('running', TenantAgentState::forTenant($t)->where('scope_id', 'x')->first()->state);
    }

    public function test_tripwire_demotes_after_three_rejected_approvals_in_a_row(): void
    {
        $t = (string) $this->tenant->id;
        TenantSkill::create(['tenant_id' => $t, 'skill_slug' => 'cold-email-drafting', 'enabled' => true, 'autonomy_level' => 'assisted']);

        $insert = function (string $status, int $minutesAgo) use ($t): void {
            DB::table('approvals')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $t, 'requester_id' => (string) $this->admin->id,
                'approval_type' => 'artifact', 'resource_type' => 'agent_artifact', 'resource_id' => (string) Str::uuid(),
                'reason' => 'draft', 'context' => json_encode(['skill_slug' => 'cold-email-drafting']), 'status' => $status,
                'requested_at' => now()->subMinutes($minutesAgo + 5), 'responded_at' => now()->subMinutes($minutesAgo),
                'created_at' => now()->subMinutes($minutesAgo + 5), 'updated_at' => now()->subMinutes($minutesAgo),
            ]);
        };

        $insert('approved', 40);
        $insert('rejected', 30);
        $insert('rejected', 20);
        $this->assertNull($this->breaker->evaluate($t, 'cold-email-drafting'), 'two in a row is not enough');

        $insert('rejected', 10);
        $reason = $this->breaker->evaluate($t, 'cold-email-drafting');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('3 rejected approvals in a row', $reason);
        $state = TenantAgentState::forTenant($t)->where('scope', 'skill')->where('scope_id', 'cold-email-drafting')->first();
        $this->assertSame('demoted', $state->state);
        $this->assertSame('tripwire', $state->tripped_by);
        $this->assertNotNull($state->resume_at, 'config resume_after_minutes sets an auto-resume');
        $this->assertSame('human_led', TenantSkill::forTenant($t)->where('skill_slug', 'cold-email-drafting')->first()->autonomy_level);

        // Idempotent while tripped; after a human resume the same three rejections do not re-trip.
        $this->assertNotNull($this->breaker->evaluate($t, 'cold-email-drafting'));
        $this->breaker->resume($t, 'skill', 'cold-email-drafting');
        $this->assertNull($this->breaker->evaluate($t, 'cold-email-drafting'));
    }

    public function test_breaker_endpoint_lists_and_trips_states(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/agents/breaker')
            ->assertOk()
            ->assertJsonPath('data.paused_everything', false)
            ->assertJsonPath('data.sends_blocked', false);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/agents/breaker', ['preset' => 'stop_sends', 'reason' => 'Bounce spike'])
            ->assertOk()
            ->assertJsonPath('data.sends_blocked', true)
            ->assertJsonPath('data.changed.scope', 'tool_risk')
            ->assertJsonPath('data.changed.scope_id', 'send');

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/agents/breaker', ['action' => 'pause', 'scope' => 'tenant'])
            ->assertOk()
            ->assertJsonPath('data.paused_everything', true);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/agents/breaker', ['action' => 'resume', 'scope' => 'tenant'])
            ->assertOk()
            ->assertJsonPath('data.paused_everything', false)
            ->assertJsonPath('data.sends_blocked', true);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/agents/breaker', ['action' => 'pause', 'scope' => 'tool_risk', 'scope_id' => 'nuke'])
            ->assertStatus(422);

        $this->assertCount(2, $this->breaker->states((string) $this->tenant->id));
    }
}
