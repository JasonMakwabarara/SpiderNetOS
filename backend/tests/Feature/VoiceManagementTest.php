<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\VoiceNumber;
use App\Models\VoiceQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * VoiceManagementTest — Phase D
 *
 * Tests for the authenticated voice management API:
 * - List / create / update / delete voice numbers
 * - Quota read and update
 * - Call detail and transcript export
 */
class VoiceManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Mgmt Tenant',
            'slug' => 'mgmt-'.Str::lower(Str::random(10)),
            'status' => 'active',
            'plan' => 'pro',
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            // Voice management routes sit behind the onboarding gate — an
            // un-onboarded user is 403'd before reaching the controller.
            'onboarding_completed_at' => now(),
        ]);
    }

    public function test_list_numbers_returns_empty_for_new_tenant(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/voice/numbers');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_create_number_succeeds_with_valid_payload(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/voice/numbers', [
                'phone_number' => '+15551234567',
                'provider' => 'twilio',
                'agent_id' => 'voice_sales',
                'approval_policy' => 'off',
                'allow_outbound' => false,
                'config' => ['greeting' => 'Hello!'],
            ]);

        $response->assertStatus(201);
        $this->assertSame('+15551234567', $response->json('data.phone_number'));

        $this->assertDatabaseHas('voice_numbers', [
            'phone_number' => '+15551234567',
            'tenant_id' => $this->user->tenant_id,
        ]);
    }

    public function test_update_number_changes_approval_policy(): void
    {
        $number = VoiceNumber::create([
            'tenant_id' => $this->user->tenant_id,
            'phone_number' => '+15559990001',
            'provider' => 'twilio',
            'agent_id' => 'voice_receptionist',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("/api/voice/numbers/{$number->id}", [
                'approval_policy' => 'strict',
                'allow_outbound' => true,
            ]);

        $response->assertStatus(200);
        $this->assertSame('strict', $response->json('data.approval_policy'));
    }

    public function test_delete_number_removes_record(): void
    {
        $number = VoiceNumber::create([
            'tenant_id' => $this->user->tenant_id,
            'phone_number' => '+15559990002',
            'provider' => 'twilio',
            'agent_id' => 'voice_receptionist',
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/voice/numbers/{$number->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('voice_numbers', ['id' => $number->id]);
    }

    public function test_cannot_access_other_tenant_number(): void
    {
        $otherTenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Other', 'slug' => 'other-'.Str::lower(Str::random(10)), 'status' => 'active', 'plan' => 'starter',
        ]);

        $number = VoiceNumber::create([
            'tenant_id' => $otherTenant->id,
            'phone_number' => '+15558880001',
            'provider' => 'twilio',
            'agent_id' => 'voice_receptionist',
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/api/voice/numbers/{$number->id}", ['is_active' => false])
            ->assertStatus(404);
    }

    // ─── Quotas ───────────────────────────────────────────────────────────────

    public function test_get_quotas_creates_default_row(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/voice/quotas');

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('data.monthly_minutes_cap'));

        $this->assertDatabaseHas('voice_quotas', ['tenant_id' => $this->user->tenant_id]);
    }

    public function test_update_quotas_sets_caps(): void
    {
        $response = $this->actingAs($this->user)->putJson('/api/voice/quotas', [
            'monthly_minutes_cap' => 500,
            'outbound_cap' => 50,
            'sms_cap' => 200,
        ]);

        $response->assertStatus(200);
        $this->assertSame(500, $response->json('data.monthly_minutes_cap'));
        $this->assertSame(50, $response->json('data.outbound_cap'));
    }

    public function test_quota_model_can_make_call_when_unlimited(): void
    {
        $quota = new VoiceQuota(['outbound_cap' => 0, 'outbound_used' => 9999]);
        $this->assertTrue($quota->canMakeCall());
    }

    public function test_quota_model_blocks_call_when_cap_reached(): void
    {
        $quota = new VoiceQuota(['outbound_cap' => 10, 'outbound_used' => 10]);
        $this->assertFalse($quota->canMakeCall());
    }
}
