<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\PackEntitlement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end: purchased -> interviewing -> script_drafted -> awaiting_approval
 * -> approved -> live, driven entirely through the HTTP API (same path the
 * cockpit uses), including the real ApprovalController::approve() endpoint.
 *
 * Requires Postgres + pgvector — see LeadApiTest for why this doesn't run
 * against sqlite :memory:. Verified manually against this exact scenario
 * during development (see docs/internal/awareness-list.md).
 */
class FunnelSetupPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        $tenant = Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Pipeline Test Co',
            'slug' => 'pipeline-test-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);

        // /api/sales/* is gated by pack.entitled:sales-crm.
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

    private function createUser(Tenant $tenant): User
    {
        return User::create([
            'name' => 'Owner',
            'email' => 'owner@'.Str::lower(Str::random(8)).'.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]);
    }

    private const ANSWERS = [
        'origin_story' => 'Started to fix scheduling for clinics.',
        'mission' => 'Never leave a message unanswered for an hour.',
        'vision' => 'Every small clinic running on our platform.',
        'ninety_day_target' => '30 new paying clinics',
        'core_offer' => 'Appointment scheduling software for clinics',
        'pricing_model' => 'Subscription, $99/month',
        'proof_points' => 'Cut no-shows by 40%',
        'ideal_customer' => 'A 2-5 doctor clinic',
        'disqualifiers' => 'Solo practitioners',
        'common_objection' => 'We already use a paper calendar',
        'preferred_tone' => 'warm and professional',
        'response_ownership' => 'draft for me to approve',
        'team_size' => '3-10 people',
    ];

    public function test_full_pipeline_purchase_to_live(): void
    {
        $tenant = $this->createTenant();
        $user = $this->actingAs($this->createUser($tenant), 'sanctum');

        // 1. Start
        $user->postJson('/api/sales/funnel-setup/start')->assertOk()->assertJsonPath('data.funnel_setup.status', 'interviewing');

        // 2. Answer every question until the interview reports done.
        $questionCount = 0;
        while (true) {
            $show = $user->getJson('/api/sales/funnel-setup')->assertOk()->json('data');
            if ($show['next']['done']) {
                break;
            }
            $questionId = $show['next']['question']['id'];
            $answer = self::ANSWERS[$questionId] ?? 'A reasonable answer.';
            $user->postJson('/api/sales/funnel-setup/answer', ['question_id' => $questionId, 'answer' => $answer])->assertOk();
            $questionCount++;
            $this->assertLessThan(30, $questionCount, 'Interview loop did not terminate.');
        }

        // 3. Draft script
        $draft = $user->postJson('/api/sales/funnel-setup/draft-script')->assertCreated()->json('data.script');
        $this->assertSame('draft', $draft['status']);
        $this->assertStringContainsString('Appointment scheduling', $draft['content']['email']['opener']);

        // 4. Submit for approval
        $approval = $user->postJson("/api/sales/scripts/{$draft['id']}/submit")->assertOk()->json('data.approval');
        $this->assertSame('pending', $approval['status']);
        $this->assertSame('sales_script', $approval['resource_type']);
        $user->getJson('/api/sales/funnel-setup')->assertJsonPath('data.funnel_setup.status', 'awaiting_approval');

        // 5. Approve via the real /api/approvals endpoint (not a service-layer
        // shortcut) — this is the exact path the cockpit's Approvals page uses,
        // and where the resolved_by/resolved_at column-name bug previously
        // made every approval fail (see awareness-list.md).
        $approveResponse = $user->postJson("/api/approvals/{$approval['id']}/approve", ['reason' => 'Looks great.']);
        $approveResponse->assertOk();
        $approveResponse->assertJsonPath('status', 'approved');

        // 6. Funnel is live and pack agents are activated.
        $final = $user->getJson('/api/sales/funnel-setup')->assertOk()->json('data.funnel_setup');
        $this->assertSame('live', $final['status']);
        $this->assertNotNull($final['went_live_at']);

        $this->assertDatabaseHas('agents', [
            'tenant_id' => $tenant->id,
            'slug' => 'sales_crm_crm',
            'status' => 'active',
        ]);
    }

    public function test_rejecting_the_script_returns_setup_to_interviewing_on_revision_request(): void
    {
        $tenant = $this->createTenant();
        $user = $this->actingAs($this->createUser($tenant), 'sanctum');

        $user->postJson('/api/sales/funnel-setup/start');
        foreach (self::ANSWERS as $questionId => $answer) {
            $user->postJson('/api/sales/funnel-setup/answer', ['question_id' => $questionId, 'answer' => $answer]);
        }
        $draft = $user->postJson('/api/sales/funnel-setup/draft-script')->json('data.script');
        $approval = $user->postJson("/api/sales/scripts/{$draft['id']}/submit")->json('data.approval');

        $rejectResponse = $user->postJson("/api/approvals/{$approval['id']}/reject", ['reason' => 'Not quite our voice.']);
        $rejectResponse->assertOk();

        $user->getJson('/api/sales/funnel-setup')->assertJsonPath('data.funnel_setup.status', 'rejected');

        $revised = $user->postJson('/api/sales/funnel-setup/request-revision')->assertOk();
        $revised->assertJsonPath('data.funnel_setup.status', 'interviewing');
    }
}
