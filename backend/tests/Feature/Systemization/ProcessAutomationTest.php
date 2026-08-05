<?php

declare(strict_types=1);

namespace Tests\Feature\Systemization;

use App\Models\BusinessProcess;
use App\Models\BusinessSystem;
use App\Models\Flow;
use App\Models\Sop;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Systemization\ProcessRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Accountable ownership loop: SOP → compiled Flow → run → write-back →
 * two failures → escalation Approval → answer → new SOP revision.
 */
class ProcessAutomationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $founder;
    private BusinessProcess $process;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Automation Co',
            'slug' => 'automation-' . Str::lower(Str::random(6)),
            'status' => 'active',
            'plan' => 'growth',
        ]);

        $this->founder = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Founder',
            'email' => Str::lower(Str::random(8)) . '@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]);

        Sanctum::actingAs($this->founder);

        $this->postJson('/api/systemization/bootstrap');
        $sales = BusinessSystem::query()->where('function', 'sales')->first();

        $processId = $this->postJson("/api/systemization/systems/{$sales->id}/processes", [
            'name' => 'Send call reminders',
            'effort_size' => 1,
        ])->json('data.id');

        $this->process = BusinessProcess::findOrFail($processId);
    }

    private function publishSop(): Sop
    {
        $sopId = $this->postJson("/api/systemization/processes/{$this->process->id}/sops", [
            'title' => 'Send call reminders',
            'purpose' => 'Booked calls without reminders no-show 40% of the time; reminders protect sales capacity.',
            'trigger' => 'When a lead books a call in the calendar.',
            'tools' => ['Google Calendar', 'Twilio SMS'],
            'steps' => [
                'Open the booking in Google Calendar and copy the lead phone number into the reminder template.',
                'Schedule the SMS reminder in Twilio for 24 hours and 1 hour before the call start time.',
                'Mark the booking with the "reminded" label so the pipeline report counts it as covered.',
            ],
            'quality_criteria' => ['Both reminders scheduled within 10 minutes of the booking being created.'],
        ])->json('data.id');

        $this->postJson("/api/systemization/sops/{$sopId}/publish")->assertOk();

        return Sop::findOrFail($sopId);
    }

    public function test_automate_requires_published_sop(): void
    {
        $this->postJson("/api/systemization/processes/{$this->process->id}/automate")
            ->assertStatus(422);
    }

    public function test_automate_compiles_sop_into_published_flow(): void
    {
        $sop = $this->publishSop();

        $response = $this->postJson("/api/systemization/processes/{$this->process->id}/automate", [
            'schedule' => 'daily_morning',
        ]);

        $response->assertOk();

        $this->process->refresh();
        $this->assertNotNull($this->process->flow_id);
        $this->assertSame('daily_morning', $this->process->schedule_cron);

        $flow = Flow::findOrFail($this->process->flow_id);
        $this->assertSame('published', $flow->status);
        $this->assertSame('SOP: ' . $sop->title, $flow->name);
        $this->assertSame('daily_morning', $flow->getAttribute('schedule_cron'));

        // trigger + 3 steps + report = 5 nodes, chained linearly
        $this->assertCount(5, $flow->dag['nodes']);
        $this->assertCount(4, $flow->dag['edges']);
        $this->assertSame($sop->steps[0], $flow->dag['nodes'][1]['config']['instruction']);
        $this->assertSame(
            $sop->quality_criteria,
            $flow->dag['nodes'][4]['config']['quality_criteria'],
            'the run must end by checking the SOP success criteria',
        );

        $this->assertDatabaseHas('event_log', [
            'tenant_id' => (string) $this->tenant->id,
            'event_type' => 'systemization.process.automated',
        ]);

        // Re-automating updates the same flow instead of duplicating it.
        $this->postJson("/api/systemization/processes/{$this->process->id}/automate")->assertOk();
        $this->assertSame(1, Flow::query()->where('tenant_id', (string) $this->tenant->id)->where('name', 'like', 'SOP:%')->count());
    }

    public function test_run_executes_flow_and_writes_back_pass(): void
    {
        $this->publishSop();
        $this->postJson("/api/systemization/processes/{$this->process->id}/automate")->assertOk();

        $response = $this->postJson("/api/systemization/processes/{$this->process->id}/run");

        $response->assertOk();
        $this->assertSame('passed', $response->json('data.process.last_run_status'));

        $this->process->refresh();
        $this->assertNotNull($this->process->last_run_at);
        $this->assertSame(0, $this->process->consecutive_failures);
        $this->assertFalse($this->process->needs_attention);

        $this->assertDatabaseHas('event_log', [
            'tenant_id' => (string) $this->tenant->id,
            'event_type' => 'systemization.process.run_recorded',
        ]);
    }

    public function test_run_without_runbook_is_rejected(): void
    {
        $this->postJson("/api/systemization/processes/{$this->process->id}/run")
            ->assertStatus(422);
    }

    public function test_two_failures_escalate_to_approval(): void
    {
        $recorder = app(ProcessRunRecorder::class);

        $recorder->record($this->process, ['execution_id' => (string) Str::uuid(), 'status' => 'failed']);
        $this->process->refresh();
        $this->assertSame(1, $this->process->consecutive_failures);
        $this->assertFalse($this->process->needs_attention, 'first failure must not escalate');

        $recorder->record($this->process, ['execution_id' => (string) Str::uuid(), 'status' => 'failed', 'errors' => ['Twilio auth expired']]);
        $this->process->refresh();

        $this->assertSame(2, $this->process->consecutive_failures);
        $this->assertTrue($this->process->needs_attention);
        $this->assertNotNull($this->process->escalation_approval_id);

        $approval = DB::table('approvals')->where('id', $this->process->escalation_approval_id)->first();
        $this->assertNotNull($approval);
        $this->assertSame('business_process', $approval->resource_type);
        $this->assertSame('pending', $approval->status);

        $this->assertDatabaseHas('event_log', [
            'tenant_id' => (string) $this->tenant->id,
            'event_type' => 'systemization.process.escalated',
        ]);

        // A third failure while already escalated must not open a second approval.
        $recorder->record($this->process->fresh(), ['execution_id' => (string) Str::uuid(), 'status' => 'failed']);
        $this->assertSame(1, DB::table('approvals')->where('resource_id', (string) $this->process->id)->count());
    }

    public function test_pass_resets_failure_streak(): void
    {
        $recorder = app(ProcessRunRecorder::class);

        $recorder->record($this->process, ['execution_id' => (string) Str::uuid(), 'status' => 'failed']);
        $recorder->record($this->process->fresh(), ['execution_id' => (string) Str::uuid(), 'status' => 'completed']);

        $this->process->refresh();
        $this->assertSame(0, $this->process->consecutive_failures);
        $this->assertSame('passed', $this->process->last_run_status);
    }

    public function test_escalation_resolution_creates_sop_revision(): void
    {
        $sop = $this->publishSop();

        $recorder = app(ProcessRunRecorder::class);
        $recorder->record($this->process, ['execution_id' => (string) Str::uuid(), 'status' => 'failed']);
        $recorder->record($this->process->fresh(), ['execution_id' => (string) Str::uuid(), 'status' => 'failed']);

        $answer = 'Twilio tokens rotate quarterly — refresh the token in Settings > Integrations before scheduling.';

        $response = $this->postJson("/api/systemization/processes/{$this->process->id}/resolve-escalation", [
            'answer' => $answer,
        ]);

        $response->assertOk();

        // The answer became a draft revision of the SOP.
        $revision = $response->json('data.sop_revision');
        $this->assertSame($sop->version + 1, $revision['version']);
        $this->assertSame('draft', $revision['status']);
        $this->assertSame($answer, $revision['notes'][0]['answer']);

        // The process is healthy again and the approval is granted.
        $this->process->refresh();
        $this->assertFalse($this->process->needs_attention);
        $this->assertSame(0, $this->process->consecutive_failures);
        $this->assertNull($this->process->escalation_approval_id);

        $this->assertSame(1, DB::table('approvals')->where('resource_id', (string) $this->process->id)->where('status', 'approved')->count());

        $this->assertDatabaseHas('event_log', [
            'tenant_id' => (string) $this->tenant->id,
            'event_type' => 'systemization.escalation.resolved',
        ]);
    }

    public function test_resolving_without_open_escalation_is_rejected(): void
    {
        $this->postJson("/api/systemization/processes/{$this->process->id}/resolve-escalation", [
            'answer' => 'No question was asked.',
        ])->assertStatus(422);
    }
}
