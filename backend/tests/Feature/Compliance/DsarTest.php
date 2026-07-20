<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\ConsentRecord;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Requires Postgres. DSAR export + erasure end-to-end through the admin API.
 */
class DsarTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private function admin(): User
    {
        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Dsar Co', 'slug' => 'dsar-'.Str::lower(Str::random(8)),
            'status' => 'active', 'plan' => 'launch', 'onboarding_completed_at' => now(),
        ]);

        return User::create([
            'name' => 'Admin', 'email' => 'a@'.Str::lower(Str::random(8)).'.test',
            'password' => bcrypt('pw'), 'tenant_id' => $this->tenant->id, 'role' => 'admin',
            'onboarding_completed_at' => now(), 'step_up_at' => now(),
        ]);
    }

    private function leadWithMessage(): Lead
    {
        $lead = Lead::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jane Prospect', 'email' => 'jane@example.test',
            'phone' => '+15551234567', 'source' => 'form', 'stage' => 'captured', 'score' => 10,
        ]);
        $convo = Conversation::create(['tenant_id' => $this->tenant->id, 'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open']);
        ConversationMessage::create([
            'tenant_id' => $this->tenant->id, 'conversation_id' => $convo->id,
            'direction' => 'inbound', 'body' => 'Hi, interested in a demo', 'status' => 'received',
        ]);

        return $lead;
    }

    public function test_export_produces_downloadable_bundle(): void
    {
        $admin = $this->admin();
        $lead = $this->leadWithMessage();

        $create = $this->actingAs($admin, 'sanctum')->postJson('/api/compliance/dsar', [
            'type' => 'export', 'subject_type' => 'lead', 'subject_id' => $lead->id,
        ]);
        $create->assertCreated()->assertJsonPath('data.status', 'completed');
        $id = $create->json('data.id');

        $show = $this->actingAs($admin, 'sanctum')->getJson("/api/compliance/dsar/{$id}");
        $show->assertOk();
        $this->assertNotNull($show->json('data.download_url'));

        $download = $this->actingAs($admin, 'sanctum')->get("/api/compliance/dsar/{$id}/download");
        $download->assertOk();
        $body = $download->getContent();
        $this->assertStringContainsString('jane@example.test', $body);
        $this->assertStringContainsString('interested in a demo', $body);
    }

    public function test_erasure_scrubs_pii_and_message_bodies(): void
    {
        $admin = $this->admin();
        $lead = $this->leadWithMessage();

        $this->actingAs($admin, 'sanctum')->postJson('/api/compliance/dsar', [
            'type' => 'erasure', 'subject_type' => 'lead', 'subject_id' => $lead->id,
        ])->assertCreated()->assertJsonPath('data.status', 'completed');

        $lead->refresh();
        $this->assertSame('[erased]', $lead->name);
        $this->assertNull($lead->email);
        $this->assertDatabaseHas('conversation_messages', ['tenant_id' => $this->tenant->id, 'body' => '[erased]']);
        // Row preserved (tombstone), not hard-deleted.
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
    }

    public function test_consent_record_latest_wins(): void
    {
        $this->admin();
        ConsentRecord::log($this->tenant->id, '+15550000000', 'whatsapp', 'granted', 'lead_form');
        $this->assertTrue(ConsentRecord::isAllowed($this->tenant->id, '+15550000000', 'whatsapp'));

        ConsentRecord::log($this->tenant->id, '+15550000000', 'whatsapp', 'stopped', 'inbound_stop');
        $this->assertFalse(ConsentRecord::isAllowed($this->tenant->id, '+15550000000', 'whatsapp'));
    }
}
