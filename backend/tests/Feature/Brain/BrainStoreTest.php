<?php

declare(strict_types=1);

namespace Tests\Feature\Brain;

use App\Models\BrainFile;
use App\Models\BrainFileVersion;
use App\Models\Event;
use App\Models\Tenant;
use App\Services\Brain\BrainConflictException;
use App\Services\Brain\BrainStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BrainStore on sqlite :memory: — versioning, section upserts, revert,
 * optimistic concurrency, tenant isolation and the brain.file.updated event.
 */
class BrainStoreTest extends TestCase
{
    use RefreshDatabase;

    private BrainStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = app(BrainStore::class);
    }

    private function tenant(string $name = 'Brain Co'): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    // Both required sections clear the manifest's min_chars (80) so the file reads as "filled".
    private const PROFILE = "# Business profile\n\n## What we do\n\nWe sell appointment scheduling software to small clinics that lose money to no-shows every single week.\n\n## What makes us different\n\nNo-shows drop by forty percent in the first month because reminders go out on WhatsApp, not by email.\n";

    public function test_write_creates_head_version_row_and_event(): void
    {
        $tenant = $this->tenant();

        $file = $this->store->write((string) $tenant->id, 'business/profile.md', self::PROFILE, ['name' => 'Brain Co'], 'human', 'user-1', null, null, 'first draft');

        $this->assertSame(1, $file->version);
        $this->assertSame('Business profile', $file->title);
        $this->assertSame('internal', $file->data_class);
        $this->assertSame(BrainFile::hashContent(self::PROFILE), $file->content_hash);
        $this->assertFalse($file->managed);
        $this->assertSame(['name' => 'Brain Co'], $file->frontmatter);

        $this->assertDatabaseHas('brain_file_versions', [
            'brain_file_id' => $file->id, 'version' => 1, 'author_type' => 'user', 'author_ref' => 'user-1', 'change_summary' => 'first draft',
        ]);
        $this->assertDatabaseHas('event_log', ['tenant_id' => (string) $tenant->id, 'event_type' => 'brain.file.updated', 'aggregate_id' => $file->id]);
        // Compared key by key: jsonb hands the payload back with its keys re-sorted, so a whole-array assertSame is driver-dependent.
        $payload = (array) Event::where('event_type', 'brain.file.updated')->first()->payload;
        $this->assertSame('business/profile.md', $payload['path']);
        $this->assertSame(1, $payload['version']);
        $this->assertSame('human', $payload['source']);
    }

    public function test_second_write_bumps_version_and_identical_write_is_a_noop(): void
    {
        $tenant = $this->tenant();
        $tenantId = (string) $tenant->id;

        $this->store->write($tenantId, 'business/profile.md', self::PROFILE);
        $v2 = $this->store->write($tenantId, 'business/profile.md', self::PROFILE."\nMore.\n");
        $this->assertSame(2, $v2->version);
        $this->assertSame(2, BrainFileVersion::where('brain_file_id', $v2->id)->count());

        $same = $this->store->write($tenantId, 'business/profile.md', self::PROFILE."\nMore.\n");
        $this->assertSame(2, $same->version, 'unchanged content does not bump the version');
        $this->assertSame(2, BrainFileVersion::where('brain_file_id', $v2->id)->count());
        $this->assertSame(2, Event::where('event_type', 'brain.file.updated')->count());
    }

    public function test_inline_frontmatter_is_lifted_into_the_column(): void
    {
        $tenant = $this->tenant();

        $file = $this->store->write((string) $tenant->id, 'offer/offer.md', "---\npricing: \$99/mo\nlinks: []\n---\n\n## Pricing\n\nMonthly.\n", ['proof_points' => ['40%']]);

        $this->assertSame(['pricing' => '$99/mo', 'links' => [], 'proof_points' => ['40%']], $file->frontmatter);
        $this->assertSame("## Pricing\n\nMonthly.\n", $file->content, 'content stores the body only');
        $this->assertStringStartsWith("---\n", $this->store->render($file));
    }

    public function test_upsert_section_preserves_other_sections_and_frontmatter(): void
    {
        $tenant = $this->tenant();
        $tenantId = (string) $tenant->id;
        $this->store->write($tenantId, 'business/profile.md', self::PROFILE, ['name' => 'Brain Co']);

        $file = $this->store->upsertSection($tenantId, 'business/profile.md', 'What makes us different', 'We answer within the hour.');

        $this->assertSame(2, $file->version);
        $sections = $this->store->sections($file->content);
        $this->assertStringStartsWith('We sell appointment scheduling software', $sections['What we do']);
        $this->assertSame('We answer within the hour.', $sections['What makes us different']);
        $this->assertSame(['name' => 'Brain Co'], $file->frontmatter);
        $this->assertStringContainsString('# Business profile', $file->content);

        $appended = $this->store->upsertSection($tenantId, 'business/profile.md', 'Where we are today', 'Three people.');
        $this->assertSame(['What we do', 'What makes us different', 'Where we are today'], array_keys($this->store->sections($appended->content)));
    }

    public function test_revert_restores_an_earlier_version_as_a_new_head(): void
    {
        $tenant = $this->tenant();
        $tenantId = (string) $tenant->id;
        $this->store->write($tenantId, 'business/profile.md', self::PROFILE, ['name' => 'v1']);
        $this->store->write($tenantId, 'business/profile.md', "## What we do\n\nChanged.\n", ['name' => 'v2']);

        $reverted = $this->store->revert($tenantId, 'business/profile.md', 1);

        $this->assertSame(3, $reverted->version);
        $this->assertSame(self::PROFILE, $reverted->content);
        $this->assertSame(['name' => 'v1'], $reverted->frontmatter);
        $this->assertSame(3, $this->store->versions($tenantId, 'business/profile.md')->count());
        $this->assertSame('Reverted to v1', $this->store->versions($tenantId, 'business/profile.md')->first()->change_summary);

        $pinned = $this->store->read($tenantId, 'business/profile.md', 2);
        $this->assertSame(2, $pinned->version);
        $this->assertSame("## What we do\n\nChanged.\n", $pinned->content);
        $this->assertFalse($pinned->exists, 'a pinned read is never a persistable head');
        $this->assertNull($this->store->read($tenantId, 'business/profile.md', 99));
    }

    public function test_stale_base_version_throws_conflict_with_current_version(): void
    {
        $tenant = $this->tenant();
        $tenantId = (string) $tenant->id;
        $this->store->write($tenantId, 'brand/voice.md', "## Tone\n\nWarm.\n");
        $this->store->write($tenantId, 'brand/voice.md', "## Tone\n\nWarm and direct.\n", [], 'human', null, 1);

        try {
            $this->store->write($tenantId, 'brand/voice.md', "## Tone\n\nSomething else.\n", [], 'human', null, 1);
            $this->fail('expected BrainConflictException');
        } catch (BrainConflictException $e) {
            $this->assertSame(2, $e->currentVersion());
            $this->assertSame(1, $e->baseVersion);
            $this->assertSame('brand/voice.md', $e->path);
        }

        $this->assertSame(2, $this->store->read($tenantId, 'brand/voice.md')->version, 'the conflicting write was rolled back');

        $this->expectException(BrainConflictException::class);
        $this->store->write($tenantId, 'brand/new.md', 'x', [], 'human', null, 3);
    }

    public function test_tenant_isolation(): void
    {
        $a = $this->tenant('Tenant A');
        $b = $this->tenant('Tenant B');

        $this->store->write((string) $a->id, 'customers/icp.md', "## Who we sell to\n\nClinics.\n");
        $this->assertNull($this->store->read((string) $b->id, 'customers/icp.md'));
        $this->assertCount(0, $this->store->versions((string) $b->id, 'customers/icp.md'));

        $mine = $this->store->write((string) $b->id, 'customers/icp.md', "## Who we sell to\n\nGyms.\n");
        $this->assertSame(1, $mine->version, 'the same path in another tenant starts at v1');
        $this->assertStringContainsString('Clinics', $this->store->read((string) $a->id, 'customers/icp.md')->content);

        $this->store->delete((string) $b->id, 'customers/icp.md');
        $this->assertNotNull($this->store->read((string) $a->id, 'customers/icp.md'));
    }

    public function test_delete_removes_head_and_history(): void
    {
        $tenant = $this->tenant();
        $tenantId = (string) $tenant->id;
        $file = $this->store->write($tenantId, 'notes/idea.md', "Just a note.\n");
        $this->store->write($tenantId, 'notes/idea.md', "Just a note, edited.\n");

        $this->store->delete($tenantId, 'notes/idea.md');

        $this->assertNull($this->store->read($tenantId, 'notes/idea.md'));
        $this->assertSame(0, BrainFileVersion::where('brain_file_id', $file->id)->count());
        $this->assertDatabaseHas('event_log', ['event_type' => 'brain.file.deleted', 'aggregate_id' => $file->id]);
        $this->store->delete($tenantId, 'notes/idea.md'); // idempotent
    }

    public function test_tree_lists_folders_with_present_and_missing_canonical_files(): void
    {
        $tenant = $this->tenant();
        $tenantId = (string) $tenant->id;
        $this->store->write($tenantId, 'business/profile.md', self::PROFILE);
        $this->store->write($tenantId, 'notes/ideas/one.md', "Note.\n");

        $tree = $this->store->tree($tenantId);

        $this->assertSame('business', $tree[0]['folder']);
        $business = collect($tree[0]['files'])->keyBy('path');
        $this->assertSame('filled', $business['business/profile.md']['status']);
        $this->assertSame(1, $business['business/profile.md']['version']);
        $this->assertTrue($business['business/profile.md']['exists']);
        $this->assertSame('missing', $business['business/alignment.md']['status']);
        $this->assertFalse($business['business/alignment.md']['exists']);

        $notes = collect(collect($tree)->firstWhere('folder', 'notes')['files'])->keyBy('path');
        $this->assertSame('filled', $notes['notes/ideas/one.md']['status']);
        $this->assertSame('internal', $notes['notes/ideas/one.md']['data_class']);

        $people = collect(collect($tree)->firstWhere('folder', 'people')['files'])->keyBy('path');
        $this->assertSame('personal', $people['people/user.md']['data_class']);
    }

    public function test_invalid_paths_are_rejected(): void
    {
        $tenant = $this->tenant();
        $this->expectException(\InvalidArgumentException::class);
        $this->store->write((string) $tenant->id, '../etc/passwd', 'x');
    }
}
