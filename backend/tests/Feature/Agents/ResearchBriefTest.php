<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\AgentArtifact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Research briefs are tenant-scoped drafts on agent_artifacts.
 * An import cannot choose a tenant or mark itself reviewed.
 */
class ResearchBriefTest extends AgentsTestCase
{
    public function test_created_brief_is_an_unreviewed_draft_for_the_caller_tenant(): void
    {
        $created = $this->api()->postJson('/api/research-briefs', $this->envelope())->assertCreated();

        $created->assertJsonPath('data.kind', 'research_brief')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.meta.review_state', 'unreviewed')
            ->assertJsonPath('data.meta.objective', 'Find three channels for a shelf-stable meal')
            ->assertJsonPath('data.meta.sources.0.origin', 'operator_supplied')
            ->assertJsonPath('data.meta.sources.0.evidence_status', 'supplied_unverified')
            ->assertJsonPath('data.approval_id', null);

        $id = (string) $created->json('data.id');
        $stored = AgentArtifact::query()->findOrFail($id);
        $this->assertSame((string) $this->tenant->id, (string) $stored->tenant_id);
        $this->assertSame(AgentArtifact::STATUS_DRAFT, $stored->status);
        $this->assertStringContainsString('Find three channels for a shelf-stable meal', (string) $stored->content);
        $this->assertStringContainsString('Public shift notice', (string) $stored->content);

        $this->api()->getJson('/api/research-briefs')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_another_tenant_cannot_read_or_download_the_brief(): void
    {
        $id = (string) $this->api()->postJson('/api/research-briefs', $this->envelope())->assertCreated()->json('data.id');

        $other = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Other', 'slug' => 'other-'.Str::lower(Str::random(6)), 'status' => 'active',
            'plan' => 'growth', 'automation_level' => 'assisted', 'onboarding_completed_at' => now(), 'settings' => [],
        ]);
        $stranger = User::create([
            'name' => 'Stranger', 'email' => 'stranger@'.Str::lower(Str::random(8)).'.test', 'password' => bcrypt('pw'),
            'tenant_id' => $other->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        $this->actingAs($stranger, 'sanctum')->getJson("/api/research-briefs/{$id}")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->get("/api/research-briefs/{$id}/markdown")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->getJson('/api/research-briefs')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_import_cannot_set_tenant_or_approval_fields(): void
    {
        $this->api()->postJson('/api/research-briefs', $this->envelope([
            'tenant_id' => (string) Str::uuid(),
            'status' => 'approved',
            'approval_id' => (string) Str::uuid(),
            'approved' => true,
        ]))->assertStatus(422);

        $this->assertSame(0, AgentArtifact::query()->where('kind', AgentArtifact::KIND_RESEARCH_BRIEF)->count());
    }

    public function test_non_http_source_is_rejected(): void
    {
        $payload = $this->envelope();
        $payload['sources'][0]['url'] = 'file:///etc/passwd';

        $this->api()->postJson('/api/research-briefs', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sources.0.url']);

        $payload['sources'][0]['url'] = 'javascript:alert(1)';
        $this->api()->postJson('/api/research-briefs', $payload)->assertStatus(422);

        $this->assertSame(0, AgentArtifact::query()->where('kind', AgentArtifact::KIND_RESEARCH_BRIEF)->count());
    }

    public function test_client_evidence_status_is_stored_as_unverified(): void
    {
        $payload = $this->envelope();
        $payload['sources'][0]['evidence_status'] = 'verified';
        $payload['sources'][0]['origin'] = 'crawler';

        $this->api()->postJson('/api/research-briefs', $payload)
            ->assertCreated()
            ->assertJsonPath('data.meta.sources.0.evidence_status', 'supplied_unverified')
            ->assertJsonPath('data.meta.sources.0.origin', 'operator_supplied');
    }

    public function test_markdown_download_is_an_attachment_with_objective_and_source_title(): void
    {
        $id = (string) $this->api()->postJson('/api/research-briefs', $this->envelope())->assertCreated()->json('data.id');

        $response = $this->api()->get("/api/research-briefs/{$id}/markdown")->assertOk();
        $disposition = (string) $response->headers->get('content-disposition');

        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.md', $disposition);
        $this->assertStringContainsString('text/markdown', (string) $response->headers->get('content-type'));
        $response->assertSee('Find three channels for a shelf-stable meal', false);
        $response->assertSee('Public shift notice', false);
    }

    public function test_oversized_body_is_rejected(): void
    {
        $this->api()->postJson('/api/research-briefs', $this->envelope([
            'draft' => str_repeat('a', 1_048_576),
        ]))->assertStatus(422);

        $this->assertSame(0, AgentArtifact::query()->where('kind', AgentArtifact::KIND_RESEARCH_BRIEF)->count());
    }

    public function test_submit_uses_the_artifact_approval_and_stays_unapplied(): void
    {
        $id = (string) $this->api()->postJson('/api/research-briefs', $this->envelope())->assertCreated()->json('data.id');

        $submitted = $this->api()->postJson("/api/artifacts/{$id}/submit")->assertOk();
        $submitted->assertJsonPath('data.status', 'submitted');
        $this->assertNotEmpty($submitted->json('data.approval_id'));

        $stored = AgentArtifact::query()->findOrFail($id);
        $this->assertSame(AgentArtifact::STATUS_SUBMITTED, $stored->status);
        $this->assertNull($stored->applied_at);
        $this->assertSame('unreviewed', ($stored->meta ?? [])['review_state'] ?? null);
    }

    /** @param  array<string, mixed>  $overrides */
    private function envelope(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Hearty Meal distributor brief',
            'objective' => 'Find three channels for a shelf-stable meal',
            'audience' => 'Canteens at mining sites',
            'supplied_facts' => ['The meal is shelf-stable.'],
            'sources' => [[
                'title' => 'Public shift notice',
                'url' => 'https://example.com/shifts',
                'excerpt' => 'Day shifts run twelve hours.',
            ]],
            'provenance' => 'Prepared in a separate workspace and pasted here.',
            'draft' => 'A convenient meal during a demanding working day.',
        ], $overrides);
    }
}
