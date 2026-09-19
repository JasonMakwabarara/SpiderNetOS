<?php

declare(strict_types=1);

namespace Tests\Feature\Brain;

use App\Models\AgentRun;
use App\Models\TenantSkill;
use App\Models\User;
use App\Services\Agents\AgentRunService;
use App\Services\Agents\RunContext;
use App\Services\Agents\RunContextFactory;
use App\Services\Brain\BrainStore;
use App\Services\Tools\ToolGateway;
use Illuminate\Support\Str;
use Tests\Feature\Agents\AgentsTestCase;

/**
 * `data_class` enforced where it is read.
 *
 * Before this, the founder's personal file, the team's files, the finance
 * summary and every weekly report were searchable by any agent — including
 * outreach agents whose output is addressed to strangers — and by any signed-in
 * member of the workspace.
 */
class DataClassContainmentTest extends AgentsTestCase
{
    /** One rare word in an internal, a confidential and a personal file. */
    private const NEEDLE = 'pelican';

    private function seedThreeClasses(): void
    {
        $store = app(BrainStore::class);
        $t = (string) $this->tenant->id;

        $store->write($t, 'business/profile.md', "## What we do\n\nWe sell the ".self::NEEDLE." scheduling system to clinics that hate phone tag.\n");
        $store->write($t, 'finance/summary.md', "## Cash\n\nThe ".self::NEEDLE." line of business held 42,000 in cash at the end of the month.\n");
        $store->write($t, 'people/user.md', "## Who I am\n\nJason, who keeps a ".self::NEEDLE." on his desk.\n");

        $this->assertSame('internal', $store->read($t, 'business/profile.md')->data_class);
        $this->assertSame('confidential', $store->read($t, 'finance/summary.md')->data_class);
        $this->assertSame('personal', $store->read($t, 'people/user.md')->data_class);
    }

    private function context(string $slug = 'cold-email-drafting'): RunContext
    {
        $run = app(AgentRunService::class)->create(
            (string) $this->tenant->id, $slug, $this->defaultInputs(), AgentRun::TRIGGER_MANUAL, null, (string) $this->admin->id,
        );

        TenantSkill::forTenant((string) $this->tenant->id)->where('skill_slug', $slug)
            ->update(['tool_overrides' => json_encode(['allow' => ['brain.search']])]);

        return app(RunContextFactory::class)->forRun($run->refresh());
    }

    public function test_an_agent_search_withholds_personal_and_undeclared_confidential(): void
    {
        $this->seedThreeClasses();

        $result = app(ToolGateway::class)->call($this->context(), 'brain.search', ['query' => self::NEEDLE, 'limit' => 20]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $paths = array_column($result['data']['hits'], 'path');

        $this->assertSame(['business/profile.md'], $paths);
        $this->assertNotContains('people/user.md', $paths, 'the founder’s own file is not searchable by an outreach agent');
        $this->assertNotContains('finance/summary.md', $paths, 'cold-email-drafting declares no confidential path');
        $this->assertSame(2, $result['data']['withheld'], 'the count is visible even though the content is not');
        $this->assertSame(['public', 'internal'], $result['data']['visible_classes']);
    }

    public function test_writing_to_a_confidential_folder_does_not_grant_reading_it(): void
    {
        $this->seedThreeClasses();

        // customer-newsletter declares reports/newsletter/** under brain.WRITES
        // and nothing confidential under brain.reads. Being able to file a
        // report into a folder is not permission to read what is already in it.
        $result = app(ToolGateway::class)->call($this->context('customer-newsletter'), 'brain.search', ['query' => self::NEEDLE, 'limit' => 20]);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $paths = array_column($result['data']['hits'], 'path');

        $this->assertSame(['business/profile.md'], $paths);
        $this->assertNotContains('finance/summary.md', $paths);
        $this->assertNotContains('people/user.md', $paths);
        $this->assertSame(['public', 'internal'], $result['data']['visible_classes']);
        $this->assertSame(2, $result['data']['withheld']);
    }

    public function test_http_search_hides_confidential_from_a_member_and_shows_it_to_an_admin(): void
    {
        $this->seedThreeClasses();

        $member = User::create([
            'name' => 'Member', 'email' => 'member-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'member', 'onboarding_completed_at' => now(),
        ]);

        $asMember = $this->actingAs($member, 'sanctum')->getJson('/api/brain/search?q='.self::NEEDLE)->assertOk();
        $memberPaths = array_column($asMember->json('data'), 'path');

        $this->assertContains('business/profile.md', $memberPaths);
        $this->assertContains('people/user.md', $memberPaths, 'personal is visible to the tenant’s own people');
        $this->assertNotContains('finance/summary.md', $memberPaths);
        $this->assertSame(1, $asMember->json('meta.withheld'));
    }

    public function test_http_search_shows_confidential_to_an_admin(): void
    {
        $this->seedThreeClasses();

        $asAdmin = $this->actingAs($this->admin, 'sanctum')->getJson('/api/brain/search?q='.self::NEEDLE)->assertOk();
        $adminPaths = array_column($asAdmin->json('data'), 'path');

        $this->assertContains('finance/summary.md', $adminPaths);
        $this->assertContains('people/user.md', $adminPaths);
        $this->assertSame(0, $asAdmin->json('meta.withheld'));
    }
}
