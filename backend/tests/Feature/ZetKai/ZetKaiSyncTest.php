<?php

declare(strict_types=1);

namespace Tests\Feature\ZetKai;

use App\Models\BrainFile;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Services\TenantKeyManager;
use App\Services\ZetKai\ZetKaiPrivacyGuard;
use App\Services\ZetKai\ZetKaiSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ZetKai -> the Knowledge brain (plan D7 §4).
 *
 * The test that matters is the private one. ZetKai is a personal Zettelkasten
 * with categories like "My Wife" and "My Journey"; SpiderNetOS is a
 * multi-tenant business platform. A note marked `local_only` must not cross,
 * and the guard has to fail closed rather than open when it cannot tell.
 */
class ZetKaiSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    /** @var list<HttpRequest> */
    private array $sent = [];

    /** @var array<string, mixed>|null */
    private $changesOverride = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Vault Co', 'slug' => 'vault-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
        $this->admin = User::create([
            'name' => 'Jason', 'email' => 'j-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        $this->flags(['zetkai.enabled' => 'on']);
        $this->connect();
        $this->fakeZetKai();
    }

    private function flags(array $values): void
    {
        config()->set('features', array_merge((array) config('features'), $values));
        Cache::flush();
    }

    private function tenantId(): string
    {
        return (string) $this->tenant->id;
    }

    private function connect(): void
    {
        $ref = 'zetkai:credentials';
        app(TenantKeyManager::class)->storeSecret($this->tenantId(), $ref, (string) json_encode([
            'base_url' => 'https://zetkai.test',
            'token' => 'zk_integration_token',
        ]));

        TenantIntegration::create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'zetkai',
            'type' => 'knowledge',
            'credentials_ref' => $ref,
            'is_active' => true,
            'config' => [],
        ]);
    }

    private function note(array $overrides = []): array
    {
        return array_merge([
            'id' => 'zk_'.Str::lower(Str::random(6)),
            'title' => 'How month-end actually works',
            'content' => "Close by the 5th. The owner does it at the kitchen table.\n",
            'categories' => [['id' => 1, 'name' => 'SpiderNetOS', 'slug' => 'spidernetos', 'local_only' => false]],
            'updated_at' => '2026-09-19T08:00:00+00:00',
        ], $overrides);
    }

    private function fakeZetKai(): void
    {
        $this->sent = [];

        Http::fake(['zetkai.test/*' => function (HttpRequest $request) {
            $this->sent[] = $request;

            if (str_contains($request->url(), '/api/sync/changes')) {
                return Http::response($this->changesOverride ?? [
                    'changes' => ['notes' => [$this->note()], 'categories' => [], 'links' => []],
                    'cursor' => '2026-09-19T09:00:00+00:00',
                ]);
            }
            if (str_contains($request->url(), '/api/notes/query')) {
                return Http::response(['results' => [['id' => 'zk_1', 'title' => 'A note', 'score' => 0.82]]]);
            }

            return Http::response(['id' => 'zk_new'], 201);
        }]);
    }

    private function serves(array $notes, ?string $cursor = '2026-09-19T09:00:00+00:00'): void
    {
        $this->changesOverride = ['changes' => ['notes' => $notes, 'categories' => [], 'links' => []], 'cursor' => $cursor];
    }

    private function sync(): array
    {
        return app(ZetKaiSyncService::class)->sync($this->tenantId());
    }

    // ------------------------------------------------------------------ //
    //  The one that matters
    // ------------------------------------------------------------------ //

    public function test_a_note_in_a_local_only_category_never_reaches_the_brain(): void
    {
        $this->serves([
            $this->note(['id' => 'zk_work', 'title' => 'Pricing for cafés']),
            $this->note([
                'id' => 'zk_private', 'title' => 'A letter to my wife',
                'content' => 'Something that is nobody else\'s business.',
                'categories' => [['id' => 2, 'name' => 'My Wife', 'slug' => 'my-wife', 'local_only' => true]],
            ]),
        ]);

        $result = $this->sync();

        $this->assertSame([1, 1], [$result['filed'], $result['withheld']]);

        $paths = BrainFile::forTenant($this->tenantId())->pluck('path')->all();
        $this->assertCount(1, $paths);
        $this->assertStringContainsString('zk_work', $paths[0]);

        // Nothing about it anywhere — not the body, not the title.
        $everything = (string) json_encode(BrainFile::forTenant($this->tenantId())->get()->toArray());
        $this->assertStringNotContainsString('my wife', mb_strtolower($everything));
        $this->assertStringNotContainsString('nobody else', mb_strtolower($everything));
    }

    public function test_the_guard_fails_closed_when_it_cannot_tell(): void
    {
        $guard = app(ZetKaiPrivacyGuard::class);

        // A note with no categories is not "public by default" — it is a note
        // we cannot classify, from a vault we do not own.
        $verdict = $guard->allows(['id' => 'x', 'title' => 'Unknown', 'content' => 'body']);
        $this->assertFalse($verdict['allowed']);
        $this->assertStringContainsString('without its categories', $verdict['reason']);

        // Every spelling of private is honoured, on the note or on a category.
        foreach (ZetKaiPrivacyGuard::PRIVATE_FLAGS as $flag) {
            $this->assertFalse($guard->allows(['categories' => [], $flag => true])['allowed'], $flag);
            $this->assertFalse($guard->allows(['categories' => [['name' => 'X', $flag => true]]])['allowed'], $flag);
        }

        $this->assertTrue($guard->allows(['categories' => [['name' => 'Work', 'local_only' => false]]])['allowed']);
        $this->assertFalse($guard->writable(['name' => 'My Journey', 'local_only' => true]));
        $this->assertTrue($guard->writable(['name' => 'SpiderNetOS']));
    }

    public function test_the_withheld_count_is_reported_rather_than_swallowed(): void
    {
        $this->serves([
            $this->note(['id' => 'zk_p1', 'categories' => [['name' => 'My Journey', 'local_only' => true]]]),
            $this->note(['id' => 'zk_p2', 'categories' => [['name' => 'My Wife', 'local_only' => true]]]),
        ]);

        $result = $this->sync();

        // A filter nobody can see is indistinguishable from one that stopped working.
        $this->assertSame(2, $result['withheld']);
        $this->assertSame(0, $result['filed']);

        $status = app(ZetKaiSyncService::class)->status($this->tenantId());
        $this->assertSame(2, $status['private_withheld']);
    }

    // ------------------------------------------------------------------ //
    //  The sync itself
    // ------------------------------------------------------------------ //

    public function test_a_note_becomes_a_confidential_brain_file_with_its_provenance(): void
    {
        $this->serves([$this->note(['id' => 'zk_42', 'title' => 'How month-end actually works'])]);

        $this->sync();

        $file = BrainFile::forTenant($this->tenantId())->firstOrFail();
        $this->assertSame('notes/zetkai/zk_42-how-month-end-actually-works.md', $file->path);
        $this->assertStringContainsString('kitchen table', $file->content);
        // `personal` comes from the manifest, which classifies notes/zetkai/**
        // more strictly than any caller would have thought to ask for.
        $this->assertSame('personal', $file->data_class);
        $this->assertSame('zk_42', $file->frontmatter['zetkai_id']);
        $this->assertSame('zetkai', $file->frontmatter['source']);
        $this->assertSame(['SpiderNetOS'], $file->frontmatter['categories']);
    }

    public function test_the_cursor_advances_and_is_sent_on_the_next_pull(): void
    {
        $this->sync();

        $integration = TenantIntegration::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame('2026-09-19T09:00:00+00:00', $integration->config['cursor']);
        $this->assertSame(1, $integration->config['notes_filed']);

        $this->sent = [];
        $this->sync();

        $this->assertStringContainsString('since=', urldecode($this->sent[0]->url()));
        $this->assertStringContainsString('2026-09-19T09%3A00%3A00%2B00%3A00', $this->sent[0]->url());
    }

    public function test_a_retitled_note_keeps_one_file_because_the_path_is_keyed_on_the_id(): void
    {
        $this->serves([$this->note(['id' => 'zk_7', 'title' => 'First title'])]);
        $this->sync();

        $this->serves([$this->note(['id' => 'zk_7', 'title' => 'First title', 'content' => 'Revised body.'])]);
        $this->sync();

        $this->assertSame(1, BrainFile::forTenant($this->tenantId())->count());
        $this->assertStringContainsString('Revised body.', BrainFile::forTenant($this->tenantId())->firstOrFail()->content);
    }

    public function test_a_deleted_note_is_tombstoned_rather_than_removed(): void
    {
        $this->serves([$this->note(['id' => 'zk_gone', 'title' => 'An old thought'])]);
        $this->sync();

        $this->serves([$this->note(['id' => 'zk_gone', 'title' => 'An old thought', 'deleted_at' => '2026-09-19T10:00:00+00:00'])]);
        $result = $this->sync();

        $this->assertSame([0, 1], [$result['filed'], $result['tombstoned']]);

        // The path survives, so anything that linked to it still resolves to an
        // honest answer rather than a missing file.
        $file = BrainFile::forTenant($this->tenantId())->where('path', 'like', '%zk_gone%')->firstOrFail();
        $this->assertTrue($file->frontmatter['deleted']);
        $this->assertStringContainsString('deleted in ZetKai', $file->content);
    }

    public function test_no_vector_ever_crosses(): void
    {
        $this->serves([$this->note([
            'id' => 'zk_vec',
            'embedding' => array_fill(0, 1024, 0.01),
            'passages' => [['text' => 'a passage', 'embedding' => array_fill(0, 1024, 0.02)]],
        ])]);

        $this->sync();

        // ZetKai embeds at 1024 dimensions and SpiderNetOS at 384. A copied
        // vector is not a worse match, it is a meaningless number that would
        // poison retrieval silently instead of failing.
        $file = BrainFile::forTenant($this->tenantId())->firstOrFail();
        $this->assertArrayNotHasKey('embedding', $file->frontmatter);
        $this->assertStringNotContainsString('0.01', (string) json_encode($file->frontmatter));
        $this->assertStringNotContainsString('0.02', $file->content);
    }

    // ------------------------------------------------------------------ //
    //  Gates
    // ------------------------------------------------------------------ //

    public function test_the_flag_and_the_connection_both_gate_the_sync(): void
    {
        $this->flags(['zetkai.enabled' => 'off']);
        $this->assertSame('disabled', $this->sync()['reason']);
        $this->assertSame([], $this->sent);

        $this->flags(['zetkai.enabled' => 'on']);
        TenantIntegration::where('tenant_id', $this->tenant->id)->update(['is_active' => false]);
        $this->assertSame('not_connected', $this->sync()['reason']);
        $this->assertSame([], $this->sent);
    }

    public function test_the_token_travels_as_a_bearer_and_never_in_the_url(): void
    {
        $this->sync();

        $this->assertSame('Bearer zk_integration_token', $this->sent[0]->header('Authorization')[0]);
        $this->assertStringNotContainsString('zk_integration_token', $this->sent[0]->url());
    }

    // ------------------------------------------------------------------ //
    //  API
    // ------------------------------------------------------------------ //

    public function test_the_api_reports_status_and_syncs_on_demand(): void
    {
        $status = $this->actingAs($this->admin, 'sanctum')->getJson('/api/brain/zetkai/status')->assertOk();
        $this->assertTrue($status->json('data.enabled'));
        $this->assertTrue($status->json('data.connected'));
        $this->assertNull($status->json('data.cursor'));

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/brain/zetkai/sync-now')
            ->assertOk()
            ->assertJsonPath('data.filed', 1)
            ->assertJsonPath('data.status.notes_filed', 1);

        $this->flags(['zetkai.enabled' => 'off']);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/brain/zetkai/sync-now')
            ->assertStatus(409)->assertJsonPath('reason', 'disabled');
    }

    public function test_pulling_a_personal_vault_is_admin_only(): void
    {
        $member = User::create([
            'name' => 'Member', 'email' => 'm-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'member', 'onboarding_completed_at' => now(),
        ]);

        $this->actingAs($member, 'sanctum')->postJson('/api/brain/zetkai/sync-now')->assertForbidden();
        $this->actingAs($member, 'sanctum')->getJson('/api/brain/zetkai/status')->assertOk();
    }

    public function test_the_zetkai_routes_require_authentication(): void
    {
        $this->getJson('/api/brain/zetkai/status')->assertUnauthorized();
        $this->postJson('/api/brain/zetkai/sync-now')->assertUnauthorized();
    }

    public function test_one_tenants_vault_is_not_anothers(): void
    {
        $this->sync();

        $other = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Other Co', 'slug' => 'other-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
        $otherAdmin = User::create([
            'name' => 'Other', 'email' => 'o-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $other->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        $this->actingAs($otherAdmin, 'sanctum')->getJson('/api/brain/zetkai/status')
            ->assertOk()->assertJsonPath('data.connected', false);
        $this->assertSame(0, BrainFile::forTenant((string) $other->id)->count());
    }
}
