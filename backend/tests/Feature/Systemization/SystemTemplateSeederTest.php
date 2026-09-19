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

class SystemTemplateSeederTest extends TestCase
{
    use RefreshDatabase;

    // Shapes of packages/feature-packs/business-systemization/templates/*.yaml
    private const RECRUITMENT_SYSTEMS = 3;   // Personal Recruiting, Interview Pipeline, Onboarding & First Days

    private const RECRUITMENT_PROCESSES = 10;

    private const RETRAINING_SYSTEMS = 3;    // Field Retraining Protocol, Mastery Coaching, Atmosphere & Standards

    private const RETRAINING_PROCESSES = 12;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('Template Co');

        Sanctum::actingAs(User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Founder',
            'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]));
    }

    private function makeTenant(string $name, string $status = 'active'): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => $status,
            'plan' => 'growth',
        ]);
    }

    private function tenantSystems(Tenant $tenant)
    {
        return BusinessSystem::query()->where('tenant_id', (string) $tenant->id);
    }

    private function tenantProcesses(Tenant $tenant)
    {
        return BusinessProcess::query()->where('tenant_id', (string) $tenant->id);
    }

    private function tenantSops(Tenant $tenant)
    {
        return Sop::query()->where('tenant_id', (string) $tenant->id);
    }

    public function test_seeder_creates_systems_processes_and_published_sops(): void
    {
        $this->artisan('systems:seed-templates', ['--tenant' => (string) $this->tenant->id])
            ->assertExitCode(0);

        $this->assertSame(
            self::RECRUITMENT_SYSTEMS + self::RETRAINING_SYSTEMS,
            $this->tenantSystems($this->tenant)->count(),
        );
        $this->assertSame(
            self::RECRUITMENT_PROCESSES + self::RETRAINING_PROCESSES,
            $this->tenantProcesses($this->tenant)->count(),
        );

        $this->assertSame(
            self::RECRUITMENT_SYSTEMS,
            $this->tenantSystems($this->tenant)->where('function', 'recruitment')->count(),
        );
        $this->assertSame(
            self::RETRAINING_SYSTEMS,
            $this->tenantSystems($this->tenant)->where('function', 'retraining')->count(),
        );

        // Every seeded process is founder-owned, positioned, and carries a published v1 SOP.
        $processes = $this->tenantProcesses($this->tenant)->with('sops')->get();
        $this->assertSame(
            self::RECRUITMENT_PROCESSES + self::RETRAINING_PROCESSES,
            $this->tenantSops($this->tenant)->count(),
        );

        foreach ($processes as $process) {
            $this->assertSame('founder', $process->owner_type);
            $this->assertSame('founder_owned', $process->status);
            $this->assertCount(1, $process->sops, "process {$process->name} should have exactly one SOP");

            $sop = $process->sops->first();
            $this->assertSame(1, $sop->version);
            $this->assertSame('published', $sop->status);
            $this->assertNotEmpty($sop->title);
            $this->assertNotEmpty($sop->steps);
            $this->assertNotEmpty($sop->quality_criteria);
        }

        // Spot-check one system per template file, with effort_size and position from yaml.
        $recruiting = $this->tenantSystems($this->tenant)
            ->where('function', 'recruitment')->where('name', 'Personal Recruiting')->first();
        $this->assertNotNull($recruiting);

        $sourcing = $this->tenantProcesses($this->tenant)
            ->where('system_id', (string) $recruiting->id)
            ->where('name', 'Everyday network sourcing with contact cards')
            ->first();
        $this->assertNotNull($sourcing);
        $this->assertSame(2, $sourcing->effort_size);
        $this->assertSame(0, $sourcing->position);

        $this->assertNotNull(
            $this->tenantSystems($this->tenant)
                ->where('function', 'retraining')->where('name', 'Mastery Coaching')->first(),
        );

        $this->assertDatabaseHas('event_log', [
            'tenant_id' => (string) $this->tenant->id,
            'event_type' => 'systemization.templates_seeded',
        ]);
    }

    public function test_seeder_is_idempotent_on_second_run(): void
    {
        $this->artisan('systems:seed-templates', ['--tenant' => (string) $this->tenant->id])
            ->assertExitCode(0);

        $systems = $this->tenantSystems($this->tenant)->count();
        $processes = $this->tenantProcesses($this->tenant)->count();
        $sops = $this->tenantSops($this->tenant)->count();
        $sopIds = $this->tenantSops($this->tenant)->orderBy('id')->pluck('id')->all();

        $this->artisan('systems:seed-templates', ['--tenant' => (string) $this->tenant->id])
            ->assertExitCode(0);

        $this->assertSame($systems, $this->tenantSystems($this->tenant)->count());
        $this->assertSame($processes, $this->tenantProcesses($this->tenant)->count());
        $this->assertSame($sops, $this->tenantSops($this->tenant)->count());

        // Existing SOP versions are kept, not replaced.
        $this->assertSame($sopIds, $this->tenantSops($this->tenant)->orderBy('id')->pluck('id')->all());
    }

    public function test_function_option_filters_templates(): void
    {
        $this->artisan('systems:seed-templates', [
            '--tenant' => (string) $this->tenant->id,
            '--function' => 'retraining',
        ])->assertExitCode(0);

        $this->assertSame(self::RETRAINING_SYSTEMS, $this->tenantSystems($this->tenant)->count());
        $this->assertSame(self::RETRAINING_PROCESSES, $this->tenantProcesses($this->tenant)->count());
        $this->assertSame(0, $this->tenantSystems($this->tenant)->where('function', 'recruitment')->count());
    }

    public function test_without_tenant_option_all_active_tenants_are_seeded(): void
    {
        $other = $this->makeTenant('Other Active Co');
        $suspended = $this->makeTenant('Suspended Co', 'suspended');

        $this->artisan('systems:seed-templates')->assertExitCode(0);

        $expected = self::RECRUITMENT_SYSTEMS + self::RETRAINING_SYSTEMS;
        $this->assertSame($expected, $this->tenantSystems($this->tenant)->count());
        $this->assertSame($expected, $this->tenantSystems($other)->count());
        $this->assertSame(0, $this->tenantSystems($suspended)->count());
    }

    public function test_retraining_is_a_valid_function_on_the_create_endpoint(): void
    {
        $response = $this->postJson('/api/systemization/systems', [
            'function' => 'retraining',
            'name' => 'Skill Rebuild Lab',
            'goal' => 'Bring underperformers back to standard through structured coaching.',
        ]);

        $response->assertStatus(201);
        $this->assertSame('retraining', $response->json('data.function'));

        // Unknown functions still rejected.
        $this->postJson('/api/systemization/systems', [
            'function' => 'gardening',
            'name' => 'Not a thing',
        ])->assertStatus(422);
    }

    public function test_bootstrap_can_opt_into_template_seeding(): void
    {
        $response = $this->postJson('/api/systemization/bootstrap', ['seed_templates' => true]);

        $response->assertOk();
        $this->assertCount(6, $response->json('data.created'));
        $this->assertTrue($response->json('data.templates_seeded'));

        // Six core functions + the recruitment/retraining template systems.
        $this->assertSame(
            6 + self::RECRUITMENT_SYSTEMS + self::RETRAINING_SYSTEMS,
            $this->tenantSystems($this->tenant)->count(),
        );
        $this->assertSame(
            self::RECRUITMENT_PROCESSES + self::RETRAINING_PROCESSES,
            $this->tenantProcesses($this->tenant)->count(),
        );

        // Default bootstrap stays template-free (six functions only).
        $plain = $this->makeTenant('Plain Co');
        Sanctum::actingAs(User::create([
            'tenant_id' => $plain->id,
            'name' => 'Founder Two',
            'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]));

        $this->postJson('/api/systemization/bootstrap')->assertOk();
        $this->assertSame(6, $this->tenantSystems($plain)->count());
    }
}
