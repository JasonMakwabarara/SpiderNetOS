<?php

declare(strict_types=1);

namespace Tests\Feature\Brain;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Brain\BrainStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * /api/brain/* through the real protected route group: tree, show, update
 * (create + base_version + 409), versions, revert, delete, gaps, readiness,
 * search, sync, proposals and cross-tenant isolation.
 */
class BrainApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Brain API Co');
        $this->user = $this->makeUser($this->tenant);
    }

    private function makeTenant(string $name): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(), 'name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'pro', 'onboarding_completed_at' => now(),
        ]);
    }

    private function makeUser(Tenant $tenant): User
    {
        return User::create([
            'name' => 'Owner', 'email' => 'owner@'.Str::lower(Str::random(8)).'.test', 'password' => bcrypt('pw'),
            'tenant_id' => $tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);
    }

    // Both required sections clear the manifest's min_chars (80) so the file reads as "filled".
    private const PROFILE = "# Business profile\n\n## What we do\n\nAppointment scheduling software for small clinics that hate no-shows and phone tag with patients.\n\n## What makes us different\n\nWe cut no-shows by forty percent within the first month, guaranteed, or the next month is free.\n";

    public function test_tree_lists_folders_and_canonical_files(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/brain/tree')->assertOk();

        $tree = $response->json('data');
        $this->assertSame('business', $tree[0]['folder']);
        $paths = array_column($tree[0]['files'], 'path');
        $this->assertContains('business/profile.md', $paths);
        $this->assertSame('missing', $tree[0]['files'][0]['status']);
    }

    public function test_file_lifecycle_show_update_conflict_versions_revert_delete(): void
    {
        $client = $this->actingAs($this->user, 'sanctum');

        $client->getJson('/api/brain/files/business/profile.md')->assertNotFound();

        $created = $client->putJson('/api/brain/files/business/profile.md', [
            'content' => self::PROFILE, 'frontmatter' => ['name' => 'Brain API Co'], 'change_note' => 'first draft',
        ])->assertCreated();
        $this->assertSame(1, $created->json('data.version'));
        $this->assertSame('human', $created->json('data.source'));
        $this->assertSame('filled', $created->json('data.status'));
        $this->assertSame(['What we do', 'What makes us different'], $created->json('data.sections'));
        $this->assertSame(['What we do', 'What makes us different'], $created->json('data.spec.required_sections'));

        $shown = $client->getJson('/api/brain/files/business/profile.md')->assertOk();
        $this->assertSame(self::PROFILE, $shown->json('data.content'));
        $this->assertSame(['name' => 'Brain API Co'], $shown->json('data.frontmatter'));

        $updated = $client->putJson('/api/brain/files/business/profile.md', [
            'content' => self::PROFILE."\n## Where we are today\n\nThree people and forty clinics.\n", 'base_version' => 1,
        ])->assertOk();
        $this->assertSame(2, $updated->json('data.version'));
        $this->assertSame(['name' => 'Brain API Co'], $updated->json('data.frontmatter'), 'a PUT without frontmatter keeps the facts');

        $conflict = $client->putJson('/api/brain/files/business/profile.md', ['content' => 'stale edit', 'base_version' => 1]);
        $conflict->assertStatus(409);
        $this->assertSame(2, $conflict->json('current_version'));
        $this->assertSame(2, $client->getJson('/api/brain/files/business/profile.md')->json('data.version'), 'the stale write was rejected');

        $versions = $client->getJson('/api/brain/files/business/profile.md/versions')->assertOk()->json('data');
        $this->assertSame([2, 1], array_column($versions, 'version'));
        $this->assertSame('first draft', $versions[1]['change_summary']);

        $pinned = $client->getJson('/api/brain/files/business/profile.md?version=1')->assertOk();
        $this->assertSame(self::PROFILE, $pinned->json('data.content'));
        $client->getJson('/api/brain/files/business/profile.md?version=9')->assertNotFound();

        $reverted = $client->postJson('/api/brain/files/business/profile.md/revert', ['version' => 1])->assertOk();
        $this->assertSame(3, $reverted->json('data.version'));
        $this->assertSame(self::PROFILE, $reverted->json('data.content'));
        $client->postJson('/api/brain/files/business/profile.md/revert', ['version' => 42])->assertNotFound();
        $client->postJson('/api/brain/files/business/profile.md/revert', [])->assertStatus(422);

        $client->deleteJson('/api/brain/files/business/profile.md')->assertOk()->assertJsonPath('data.deleted', true);
        $client->getJson('/api/brain/files/business/profile.md')->assertNotFound();
        $client->deleteJson('/api/brain/files/business/profile.md')->assertNotFound();
    }

    public function test_invalid_paths_and_agent_private_paths(): void
    {
        $client = $this->actingAs($this->user, 'sanctum');

        $client->putJson('/api/brain/files/..%2Fetc%2Fpasswd', ['content' => 'x'])->assertStatus(422);
        $client->getJson('/api/brain/files/business/..%2Fprofile.md')->assertNotFound();
        $client->putJson('/api/brain/files/workspaces/growth/scratch/ideas.md', ['content' => 'x'])->assertForbidden();
        $client->putJson('/api/brain/files/notes/idea.md', [])->assertStatus(422);
    }

    public function test_gaps_and_readiness(): void
    {
        $client = $this->actingAs($this->user, 'sanctum');

        $gaps = $client->getJson('/api/brain/gaps?requires[]=voice.tone&requires[]=offer.pricing')->assertOk()->json('data');
        $this->assertCount(2, $gaps);
        $this->assertSame(['brand/voice.md', 'Tone', 'missing_file'], [$gaps[0]['path'], $gaps[0]['section'], $gaps[0]['reason']]);
        $this->assertStringContainsString('How should we sound', $gaps[0]['question']);
        $this->assertSame('offer/offer.md', $gaps[1]['path']);

        $client->getJson('/api/brain/gaps?requires[]=not.a.key')->assertStatus(422);

        $all = $client->getJson('/api/brain/gaps')->assertOk()->json('data');
        $this->assertNotEmpty($all, 'with no requires the readiness order is checked');

        $before = $client->getJson('/api/brain/readiness')->assertOk()->json('data');
        $this->assertSame(0, $before['pct']);
        $this->assertSame('business/profile.md', $before['files'][0]['path']);
        $this->assertSame('missing', $before['files'][0]['status']);
        $this->assertNotNull($before['files'][0]['ask_prompt']);

        $client->putJson('/api/brain/files/business/profile.md', ['content' => self::PROFILE])->assertCreated();

        $after = $client->getJson('/api/brain/readiness')->assertOk()->json('data');
        $this->assertGreaterThan(0, $after['pct']);
        $this->assertSame('filled', $after['files'][0]['status']);
        $this->assertNull($after['files'][0]['ask_prompt']);

        $gapsAfter = $client->getJson('/api/brain/gaps?requires[]=business.profile')->assertOk()->json('data');
        $this->assertSame([], $gapsAfter);
    }

    public function test_search_sync_and_proposals(): void
    {
        $client = $this->actingAs($this->user, 'sanctum');
        $client->putJson('/api/brain/files/business/profile.md', ['content' => self::PROFILE])->assertCreated();
        $client->putJson('/api/brain/files/notes/idea.md', ['content' => "A note about pricing experiments.\n"])->assertCreated();

        $hits = $client->getJson('/api/brain/search?q=no-shows')->assertOk()->json('data');
        $this->assertCount(1, $hits);
        $this->assertSame('business/profile.md', $hits[0]['path']);
        $this->assertSame('What we do', $hits[0]['section']);
        $this->assertStringContainsString('no-shows', $hits[0]['snippet']);
        $this->assertSame([], $client->getJson('/api/brain/search?q=x')->assertOk()->json('data'), 'too short a query returns nothing');

        $sync = $client->postJson('/api/brain/sync')->assertOk()->json('data');
        $this->assertContains('people/user.md', $sync['written']);
        $this->assertContains('finance/summary.md', $sync['written']);
        $this->assertSame('personal', $client->getJson('/api/brain/files/people/user.md')->assertOk()->json('data.data_class'));

        $this->assertSame([], $client->getJson('/api/brain/proposals')->assertOk()->json('data'));
    }

    public function test_cross_tenant_files_are_invisible(): void
    {
        app(BrainStore::class)->write((string) $this->tenant->id, 'customers/icp.md', "## Who we sell to\n\nClinics.\n");

        $other = $this->makeUser($this->makeTenant('Other Co'));
        $client = $this->actingAs($other, 'sanctum');

        $client->getJson('/api/brain/files/customers/icp.md')->assertNotFound();
        $client->getJson('/api/brain/files/customers/icp.md/versions')->assertNotFound();
        $this->assertSame([], $client->getJson('/api/brain/search?q=Clinics')->assertOk()->json('data'));
        $this->assertSame(0, $client->getJson('/api/brain/readiness')->assertOk()->json('data.pct'));
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/brain/tree')->assertUnauthorized();
    }
}
