<?php

declare(strict_types=1);

namespace Tests\Feature\Founder;

use App\Models\ApprovalReview;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** POST /api/approvals/{id}/review (plan D8 #8): the cockpit records dwell / diff / edited / decision. */
class ApprovalReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private function tenantAndUser(): array
    {
        $tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Review Co', 'slug' => 'review-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
        $user = User::create([
            'name' => 'U', 'email' => 'u-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        return [$tenant, $user];
    }

    private function approval(Tenant $tenant, User $user): string
    {
        $id = (string) Str::uuid();
        DB::table('approvals')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'requester_id' => (string) $user->id, 'approval_type' => 'artifact',
            'resource_type' => 'agent_artifact', 'resource_id' => (string) Str::uuid(), 'reason' => 'draft', 'context' => '{}',
            'status' => 'pending', 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_records_a_review_for_a_tenant_approval(): void
    {
        [$tenant, $user] = $this->tenantAndUser();
        $approvalId = $this->approval($tenant, $user);

        $this->actingAs($user, 'sanctum')->postJson("/api/approvals/{$approvalId}/review", [
            'dwell_ms' => 12800, 'diff_expanded' => true, 'edited' => false, 'decision' => 'approved', 'meta' => ['surface' => 'batch_card'],
        ])->assertCreated()
            ->assertJsonPath('data.approval_id', $approvalId)
            ->assertJsonPath('data.dwell_ms', 12800)
            ->assertJsonPath('data.diff_expanded', true)
            ->assertJsonPath('data.edited', false)
            ->assertJsonPath('data.decision', 'approved');

        $review = ApprovalReview::forTenant((string) $tenant->id)->first();
        $this->assertNotNull($review);
        $this->assertSame((string) $user->id, $review->user_id);
        $this->assertSame(['surface' => 'batch_card'], $review->meta);
        $this->assertNotNull($review->created_at);

        // Minimal body works too (a "viewed" ping).
        $this->actingAs($user, 'sanctum')->postJson("/api/approvals/{$approvalId}/review", [])->assertCreated()->assertJsonPath('data.dwell_ms', 0);
        $this->assertSame(2, ApprovalReview::forTenant((string) $tenant->id)->count());
    }

    public function test_rejects_unknown_foreign_or_invalid_reviews(): void
    {
        [$tenant, $user] = $this->tenantAndUser();
        [$other, $otherUser] = $this->tenantAndUser();
        $foreign = $this->approval($other, $otherUser);
        $mine = $this->approval($tenant, $user);

        $this->actingAs($user, 'sanctum')->postJson("/api/approvals/{$foreign}/review", ['dwell_ms' => 1])->assertNotFound();
        $this->actingAs($user, 'sanctum')->postJson('/api/approvals/'.Str::uuid().'/review', ['dwell_ms' => 1])->assertNotFound();
        $this->actingAs($user, 'sanctum')->postJson("/api/approvals/{$mine}/review", ['decision' => 'shrugged'])->assertStatus(422);
        $this->actingAs($user, 'sanctum')->postJson("/api/approvals/{$mine}/review", ['dwell_ms' => -5])->assertStatus(422);
        $this->assertSame(0, ApprovalReview::count());
    }
}
