<?php

declare(strict_types=1);

namespace Tests\Feature\Systemization;

use App\Models\BusinessProcess;
use App\Models\BusinessSystem;
use App\Models\Sop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Systemize Co',
            'slug' => 'systemize-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'plan' => 'growth',
        ]);

        Sanctum::actingAs(User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Founder',
            'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]));
    }

    private function completeSopAnswers(): array
    {
        return [
            'title' => 'Send weekly cashflow report',
            'purpose' => 'Keeps leadership aware of runway and collection issues before they become emergencies.',
            'trigger' => 'Every Friday at 9am after bank feeds sync.',
            'tools' => ['Xero', 'Google Sheets cashflow template', 'Slack #finance'],
            'steps' => [
                'Open Xero and export the weekly bank summary as CSV from Reports > Bank Summary.',
                'Paste the CSV into the "Raw" tab of the cashflow template and confirm formulas populate the Summary tab.',
                'Post the Summary tab screenshot to Slack #finance with any invoices overdue more than 14 days flagged.',
            ],
            'quality_criteria' => ['Report posted in #finance before 11am Friday with overdue invoices flagged.'],
        ];
    }

    public function test_bootstrap_seeds_six_functions(): void
    {
        $response = $this->postJson('/api/systemization/bootstrap');

        $response->assertOk();
        $this->assertCount(6, $response->json('data.created'));

        $functions = BusinessSystem::query()
            ->where('tenant_id', (string) $this->tenant->id)
            ->pluck('function')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            ['finance', 'management', 'marketing', 'operations', 'recruitment', 'sales'],
            $functions,
        );

        // Idempotent
        $this->postJson('/api/systemization/bootstrap')->assertOk();
        $this->assertSame(6, BusinessSystem::query()->where('tenant_id', (string) $this->tenant->id)->count());
    }

    public function test_snowball_orders_founder_processes_smallest_first(): void
    {
        $this->postJson('/api/systemization/bootstrap');
        $sales = BusinessSystem::query()->where('function', 'sales')->first();

        foreach ([['Close enterprise deals', 5], ['Send call reminders', 1], ['Update CRM stages', 2]] as [$name, $size]) {
            $this->postJson("/api/systemization/systems/{$sales->id}/processes", [
                'name' => $name,
                'effort_size' => $size,
            ])->assertStatus(201);
        }

        $response = $this->getJson('/api/systemization/snowball');

        $response->assertOk();
        $names = array_column($response->json('data.queue'), 'name');
        $this->assertSame(['Send call reminders', 'Update CRM stages', 'Close enterprise deals'], $names);
        $this->assertStringContainsString('Send call reminders', $response->json('data.next_action'));
    }

    public function test_ownership_is_exclusive_and_event_sourced(): void
    {
        $this->postJson('/api/systemization/bootstrap');
        $marketing = BusinessSystem::query()->where('function', 'marketing')->first();

        $processId = $this->postJson("/api/systemization/systems/{$marketing->id}/processes", [
            'name' => 'Write weekly newsletter',
        ])->json('data.id');

        $teammate = Str::uuid()->toString();

        $response = $this->patchJson("/api/systemization/processes/{$processId}", [
            'owner_type' => 'team',
            'owner_user_id' => $teammate,
            'owner_agent_id' => Str::uuid()->toString(), // must be ignored — one owner only
        ]);

        $response->assertOk();
        $process = BusinessProcess::find($processId);
        $this->assertSame('team', $process->owner_type);
        $this->assertSame($teammate, $process->owner_user_id);
        $this->assertNull($process->owner_agent_id, 'one owner per process — agent owner must be cleared');
        $this->assertSame('delegated', $process->status);

        $this->assertDatabaseHas('event_log', [
            'tenant_id' => (string) $this->tenant->id,
            'event_type' => 'systemization.process.owner_assigned',
        ]);

        // Founder load reflects the delegation
        $map = $this->getJson('/api/systemization/map')->json('data.founder_load');
        $this->assertSame(1, $map['delegated']);
        $this->assertSame(0, $map['founder_owned']);
    }

    public function test_sop_interview_challenges_vague_answers(): void
    {
        $this->postJson('/api/systemization/bootstrap');
        $ops = BusinessSystem::query()->where('function', 'operations')->first();
        $processId = $this->postJson("/api/systemization/systems/{$ops->id}/processes", [
            'name' => 'Client onboarding',
        ])->json('data.id');

        $response = $this->postJson("/api/systemization/processes/{$processId}/sops", [
            'title' => 'Onboard client',
            'purpose' => 'Onboarding.',
            'steps' => ['Send email', 'Upload files', 'Check CRM'],
        ]);

        $response->assertStatus(422);
        $questions = $response->json('questions');
        $this->assertNotEmpty($questions);
        // It must challenge the vague steps, the missing trigger, tools, and success criteria.
        $this->assertTrue(count($questions) >= 4, 'expected multiple clarification questions, got: '.json_encode($questions));
    }

    public function test_complete_sop_is_versioned_and_publishable(): void
    {
        $this->postJson('/api/systemization/bootstrap');
        $finance = BusinessSystem::query()->where('function', 'finance')->first();
        $processId = $this->postJson("/api/systemization/systems/{$finance->id}/processes", [
            'name' => 'Weekly cashflow report',
        ])->json('data.id');

        $v1 = $this->postJson("/api/systemization/processes/{$processId}/sops", $this->completeSopAnswers());
        $v1->assertStatus(201);
        $this->assertSame(1, $v1->json('data.version'));

        $this->postJson('/api/systemization/sops/'.$v1->json('data.id').'/publish')->assertOk();

        // Revision loop: v2 supersedes v1 on publish.
        $v2 = $this->postJson("/api/systemization/processes/{$processId}/sops", $this->completeSopAnswers());
        $this->assertSame(2, $v2->json('data.version'));
        $this->postJson('/api/systemization/sops/'.$v2->json('data.id').'/publish')->assertOk();

        $this->assertSame(
            ['archived', 'published'],
            Sop::query()->where('process_id', $processId)->orderBy('version')->pluck('status')->all(),
        );

        $this->assertDatabaseHas('event_log', [
            'tenant_id' => (string) $this->tenant->id,
            'event_type' => 'systemization.sop.published',
        ]);
    }

    public function test_cross_tenant_processes_are_invisible(): void
    {
        $this->postJson('/api/systemization/bootstrap');
        $sales = BusinessSystem::query()->where('function', 'sales')->first();
        $processId = $this->postJson("/api/systemization/systems/{$sales->id}/processes", [
            'name' => 'Secret process',
        ])->json('data.id');

        $otherTenant = Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Other Co',
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'plan' => 'starter',
        ]);

        Sanctum::actingAs(User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Outsider',
            'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]));

        $this->patchJson("/api/systemization/processes/{$processId}", ['owner_type' => 'team'])
            ->assertStatus(404);

        $this->assertSame(0, count($this->getJson('/api/systemization/map')->json('data.systems')));
    }
}
