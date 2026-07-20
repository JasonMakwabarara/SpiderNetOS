<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Models\ConsentRecord;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Messaging\MessageDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Requires Postgres. An explicit consent opt-out hard-blocks outbound even when
 * the per-lead flag says opted-in; the channels endpoint lists supported channels.
 */
class ConsentGateTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(), 'name' => 'Msg Co', 'slug' => 'msg-'.Str::lower(Str::random(8)),
            'status' => 'active', 'plan' => 'launch', 'onboarding_completed_at' => now(),
        ]);
    }

    public function test_explicit_optout_blocks_send_despite_lead_flag(): void
    {
        $tenant = $this->tenant();
        $lead = Lead::create([
            'tenant_id' => $tenant->id, 'name' => 'Sam', 'whatsapp_number' => '+15557654321',
            'source' => 'form', 'stage' => 'captured', 'score' => 10,
            'consent' => ['whatsapp_opt_in' => true], // per-lead flag says OK
        ]);

        // But there's an explicit opt-out on the consent audit trail.
        ConsentRecord::log($tenant->id, '+15557654321', 'whatsapp', 'stopped', 'inbound_stop');

        $result = app(MessageDispatchService::class)->send($lead, 'whatsapp', 'Hello');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('opted out', $result['error']);
    }

    public function test_channels_endpoint_lists_supported_channels(): void
    {
        $tenant = $this->tenant();
        $user = User::create([
            'name' => 'U', 'email' => 'u@'.Str::lower(Str::random(8)).'.test',
            'password' => bcrypt('pw'), 'tenant_id' => $tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/messaging/channels');
        $res->assertOk();
        $channels = $res->json('data.available_channels');
        $this->assertContains('sms', $channels);
        $this->assertContains('whatsapp', $channels);
        $this->assertContains('email', $channels);
    }
}
