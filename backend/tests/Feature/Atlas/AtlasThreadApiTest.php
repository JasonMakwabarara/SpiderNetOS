<?php

declare(strict_types=1);

namespace Tests\Feature\Atlas;

use App\Models\AtlasThread;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** /api/atlas/sessions (plan D8 #13) — the routes cockpit/src/stores/atlas.js already calls. */
class AtlasThreadApiTest extends TestCase
{
    use RefreshDatabase;

    private function tenantAndUser(): array
    {
        $tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Thread Co', 'slug' => 'thread-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
        $user = User::create([
            'name' => 'U', 'email' => 'u-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        return [$tenant, $user];
    }

    public function test_create_show_list_and_update_a_thread(): void
    {
        [$tenant, $user] = $this->tenantAndUser();

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/atlas/sessions', ['title' => 'Q4 outreach'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Q4 outreach')
            ->assertJsonPath('data.messages', [])
            ->assertJsonPath('data.suggestions', []);
        $id = $created->json('data.id');
        $this->assertTrue(Str::isUuid($id));

        // Untitled thread, created later → listed first.
        $second = $this->actingAs($user, 'sanctum')->postJson('/api/atlas/sessions')->assertCreated()->json('data.id');

        $this->actingAs($user, 'sanctum')->getJson('/api/atlas/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.1.id', $id);

        $this->actingAs($user, 'sanctum')->patchJson("/api/atlas/sessions/{$id}", [
            'title' => 'Q4 partner outreach', 'pinned_brain_paths' => ['offer/offer.md', 'brand/voice.md', 'offer/offer.md'],
        ])->assertOk()
            ->assertJsonPath('data.title', 'Q4 partner outreach')
            ->assertJsonPath('data.pinned_brain_paths', ['offer/offer.md', 'brand/voice.md']);

        // Chat history is hydrated from the event log (aggregate atlas_session).
        app(EventStore::class)->append((string) $tenant->id, 'atlas_session', $id, 'atlas.message.received', ['role' => 'user', 'content' => 'Draft the Acme sequence', 'interaction_id' => 'i1']);
        app(EventStore::class)->append((string) $tenant->id, 'atlas_session', $id, 'atlas.message.sent', ['role' => 'atlas', 'contract' => ['action_summary' => 'Drafted 3 steps.'], 'interaction_id' => 'i1']);

        $show = $this->actingAs($user, 'sanctum')->getJson("/api/atlas/sessions/{$id}")->assertOk();
        $this->assertSame('Q4 partner outreach', $show->json('data.title'));
        $this->assertCount(2, $show->json('data.messages'));
        $this->assertSame('user', $show->json('data.messages.0.role'));
        $this->assertSame('Draft the Acme sequence', $show->json('data.messages.0.content'));
        $this->assertSame('atlas', $show->json('data.messages.1.role'));
        $this->assertSame('Drafted 3 steps.', $show->json('data.messages.1.content'));
        $this->assertNotNull($show->json('data.last_seen_at'));

        // Re-posting with thread_id returns the same thread instead of creating one.
        $this->actingAs($user, 'sanctum')->postJson('/api/atlas/sessions', ['thread_id' => $id])->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(2, AtlasThread::forTenant((string) $tenant->id)->count());
    }

    public function test_threads_are_tenant_scoped(): void
    {
        [, $user] = $this->tenantAndUser();
        [$other] = $this->tenantAndUser();
        $foreign = AtlasThread::create(['tenant_id' => $other->id, 'title' => 'Not yours']);

        $this->actingAs($user, 'sanctum')->getJson("/api/atlas/sessions/{$foreign->id}")->assertNotFound();
        $this->actingAs($user, 'sanctum')->patchJson("/api/atlas/sessions/{$foreign->id}", ['title' => 'hijack'])->assertNotFound();
        $this->actingAs($user, 'sanctum')->getJson('/api/atlas/sessions/not-a-uuid')->assertNotFound();
        $this->actingAs($user, 'sanctum')->getJson('/api/atlas/sessions')->assertOk()->assertJsonCount(0, 'data');
    }
}
