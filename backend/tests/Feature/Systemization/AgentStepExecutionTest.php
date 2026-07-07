<?php

declare(strict_types=1);

namespace Tests\Feature\Systemization;

use App\Jobs\FailStaleExecutionNodesJob;
use App\Jobs\SystemizationRunSweepJob;
use App\Models\BusinessProcess;
use App\Models\BusinessSystem;
use App\Models\Flow;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Agent runtime readiness: SOP steps of agent-owned processes execute
 * through the inference plane (or honest simulation), blocked agents fail
 * the run, the watchdog kills hung nodes, and scheduled runs write back.
 */
class AgentStepExecutionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private BusinessProcess $process;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Runtime Co',
            'slug' => 'runtime-' . Str::lower(Str::random(6)),
            'status' => 'active',
            'plan' => 'growth',
        ]);

        Sanctum::actingAs(User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Founder',
            'email' => Str::lower(Str::random(8)) . '@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]));

        $this->postJson('/api/systemization/bootstrap');
        $sales = BusinessSystem::query()->where('function', 'sales')->first();

        $processId = $this->postJson("/api/systemization/systems/{$sales->id}/processes", [
            'name' => 'Send call reminders',
            'effort_size' => 1,
        ])->json('data.id');

        $this->process = BusinessProcess::findOrFail($processId);
    }

    private function publishSopAndAssignAgent(): void
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

        $this->patchJson("/api/systemization/processes/{$this->process->id}", [
            'owner_type' => 'agent',
            'owner_agent_id' => Str::uuid()->toString(),
        ])->assertOk();

        $this->postJson("/api/systemization/processes/{$this->process->id}/automate")->assertOk();
        $this->process->refresh();
    }

    private function inferenceFake(string $text, float $cost = 0.01): void
    {
        config()->set('spidernet.agent_step_execution', 'inference');

        Http::fake([
            '*/generate' => Http::response([
                'text' => $text,
                'model' => 'llama3.1:8b',
                'tokens_used' => 210,
                'cost' => $cost,
                'latency_ms' => 730.5,
                'provider' => 'ollama',
            ]),
        ]);
    }

    public function test_agent_owned_steps_compile_to_agent_step_nodes(): void
    {
        $this->publishSopAndAssignAgent();

        $flow = Flow::findOrFail($this->process->flow_id);
        $stepActions = array_map(
            fn ($n) => $n['config']['action'] ?? null,
            array_slice($flow->dag['nodes'], 1, 3),
        );

        $this->assertSame(['agent_step', 'agent_step', 'agent_step'], $stepActions);
    }

    public function test_human_owned_steps_stay_deterministic(): void
    {
        $sopId = $this->postJson("/api/systemization/processes/{$this->process->id}/sops", [
            'title' => 'Send call reminders',
            'purpose' => 'Booked calls without reminders no-show 40% of the time; reminders protect capacity.',
            'trigger' => 'When a lead books a call.',
            'tools' => ['Google Calendar'],
            'steps' => [
                'Open the booking in Google Calendar and copy the lead phone number into the template.',
                'Schedule the SMS reminder in Twilio for 24 hours and 1 hour before the call.',
                'Mark the booking with the "reminded" label so the report counts it as covered.',
            ],
            'quality_criteria' => ['Both reminders scheduled within 10 minutes.'],
        ])->json('data.id');
        $this->postJson("/api/systemization/sops/{$sopId}/publish")->assertOk();

        // owner_type stays founder
        $this->postJson("/api/systemization/processes/{$this->process->id}/automate")->assertOk();
        $this->process->refresh();

        $flow = Flow::findOrFail($this->process->flow_id);
        $this->assertSame('log', $flow->dag['nodes'][1]['config']['action']);
    }

    public function test_simulate_mode_runs_pass_and_are_marked_simulated(): void
    {
        config()->set('spidernet.agent_step_execution', 'simulate');
        $this->publishSopAndAssignAgent();

        $this->postJson("/api/systemization/processes/{$this->process->id}/run")
            ->assertOk()
            ->assertJsonPath('data.process.last_run_status', 'passed');

        $stepResult = DB::table('execution_dag_nodes')
            ->where('node_id', 'step_1')
            ->orderByDesc('created_at')
            ->value('result');

        $this->assertNotNull($stepResult);
        $this->assertTrue((bool) (json_decode((string) $stepResult, true)['simulated'] ?? false));
    }

    public function test_inference_mode_executes_step_via_plane_and_records_cost(): void
    {
        $this->inferenceFake('Reminder scheduled in Twilio for both offsets; booking labelled "reminded".');
        $this->publishSopAndAssignAgent();

        $this->postJson("/api/systemization/processes/{$this->process->id}/run")
            ->assertOk()
            ->assertJsonPath('data.process.last_run_status', 'passed');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/generate')
            && str_contains((string) $request['prompt'], 'Google Calendar'));

        $stepResult = json_decode((string) DB::table('execution_dag_nodes')
            ->where('node_id', 'step_1')
            ->orderByDesc('created_at')
            ->value('result'), true);

        $this->assertFalse($stepResult['simulated']);
        $this->assertSame('ollama', $stepResult['provider']);

        // LLM spend flowed into the cost loop.
        $this->assertDatabaseHas('event_log', [
            'tenant_id' => (string) $this->tenant->id,
            'event_type' => 'usage.recorded',
        ]);
    }

    public function test_blocked_agent_fails_the_run(): void
    {
        $this->inferenceFake('BLOCKED: Twilio credentials are missing from the integration settings.');
        $this->publishSopAndAssignAgent();

        $this->postJson("/api/systemization/processes/{$this->process->id}/run")
            ->assertOk()
            ->assertJsonPath('data.process.last_run_status', 'failed');

        $this->process->refresh();
        $this->assertSame(1, $this->process->consecutive_failures);
    }

    public function test_inference_outage_fails_the_run_not_the_request(): void
    {
        config()->set('spidernet.agent_step_execution', 'inference');
        Http::fake(['*/generate' => Http::response('upstream unavailable', 503)]);
        $this->publishSopAndAssignAgent();

        $this->postJson("/api/systemization/processes/{$this->process->id}/run")
            ->assertOk()
            ->assertJsonPath('data.process.last_run_status', 'failed');
    }

    public function test_watchdog_fails_stale_running_nodes(): void
    {
        $this->publishSopAndAssignAgent();

        $executionId = (string) Str::uuid();
        DB::table('flow_executions')->insert([
            'id' => $executionId,
            'tenant_id' => (string) $this->tenant->id,
            'flow_id' => (string) $this->process->flow_id,
            'status' => 'running',
            'context' => '{}',
            'started_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        DB::table('execution_dag_nodes')->insert([
            'id' => (string) Str::uuid(),
            'execution_id' => $executionId,
            'tenant_id' => (string) $this->tenant->id,
            'node_id' => 'step_1',
            'node_type' => 'agent',
            'config' => '{}',
            'status' => 'running',
            'started_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        (new FailStaleExecutionNodesJob())->handle(app(\App\Services\DagExecutionService::class));

        $this->assertSame('failed', DB::table('execution_dag_nodes')
            ->where('execution_id', $executionId)->where('node_id', 'step_1')->value('status'));
        $this->assertSame('failed', DB::table('flow_executions')->where('id', $executionId)->value('status'));
    }

    public function test_sweep_records_scheduled_runs(): void
    {
        config()->set('spidernet.agent_step_execution', 'simulate');
        $this->publishSopAndAssignAgent();

        // A scheduled run happened out-of-band (via DispatchScheduledFlowsJob).
        $executionId = (string) Str::uuid();
        DB::table('flow_executions')->insert([
            'id' => $executionId,
            'tenant_id' => (string) $this->tenant->id,
            'flow_id' => (string) $this->process->flow_id,
            'status' => 'completed',
            'context' => '{}',
            'started_at' => now()->subMinutes(3),
            'completed_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(2),
        ]);

        (new SystemizationRunSweepJob())->handle(app(\App\Services\Systemization\ProcessRunRecorder::class));

        $this->process->refresh();
        $this->assertSame('passed', $this->process->last_run_status);
        $this->assertSame($executionId, $this->process->last_execution_id);

        // Idempotent: sweeping again with no new execution changes nothing.
        $before = $this->process->updated_at;
        (new SystemizationRunSweepJob())->handle(app(\App\Services\Systemization\ProcessRunRecorder::class));
        $this->assertSame($executionId, $this->process->fresh()->last_execution_id);
    }
}
