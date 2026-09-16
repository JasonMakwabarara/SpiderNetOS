<?php

declare(strict_types=1);

namespace Tests\Feature\Outreach;

use App\Models\ConsentRecord;
use App\Models\ConversationMessage;
use App\Models\Event;
use App\Models\PackEntitlement;
use App\Models\PartnerProspect;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Outreach\OutreachSender;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class PartnerApiTest extends OutreachTestCase
{
    private function admin()
    {
        return $this->actingAs($this->admin, 'sanctum');
    }

    private function member()
    {
        $user = User::create([
            'name' => 'Member', 'email' => 'member@'.Str::lower(Str::random(8)).'.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'user', 'onboarding_completed_at' => now(),
        ]);

        return $this->actingAs($user, 'sanctum');
    }

    private function rows(): array
    {
        return [
            ['name' => 'Mike Futia', 'domain' => 'https://www.tiktok.com/@mike_futia', 'primary' => 'x', 'email' => 'mike@example.test'],
            ['name' => 'Social Media Examiner', 'domain' => 'https://www.facebook.com/smexaminer/', 'primary' => 'y'],
        ];
    }

    public function test_list_show_filters_and_tenant_isolation(): void
    {
        $this->import($this->rows());

        $res = $this->admin()->getJson('/api/sales/partners')->assertOk();
        $this->assertSame(2, $res->json('summary.total'));
        $this->assertSame(1, $res->json('summary.with_email'));
        $this->assertCount(2, $res->json('data.data'));

        $this->admin()->getJson('/api/sales/partners?status=ready')->assertOk()->assertJsonCount(1, 'data.data');
        $this->admin()->getJson('/api/sales/partners?has_email=0')->assertOk()->assertJsonCount(1, 'data.data');
        $this->admin()->getJson('/api/sales/partners?platform=facebook')->assertOk()->assertJsonCount(1, 'data.data');
        $this->admin()->getJson('/api/sales/partners?q=smexaminer')->assertOk()->assertJsonPath('data.data.0.handle', 'smexaminer');

        $id = (string) PartnerProspect::forTenant($this->tenant->id)->where('handle', 'mike_futia')->value('id');
        $this->admin()->getJson("/api/sales/partners/{$id}")->assertOk()
            ->assertJsonPath('data.id', $id)->assertJsonPath('data.lead.email', 'mike@example.test')
            ->assertJsonStructure(['conversations']);

        // Another tenant's admin cannot see it.
        $foreign = Tenant::create(['id' => Str::uuid(), 'name' => 'Other', 'slug' => 'other-'.Str::lower(Str::random(6)), 'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now()]);
        PackEntitlement::create(['tenant_id' => $foreign->id, 'pack_id' => 'sales-crm', 'source' => 'granted', 'provider' => 'manual', 'status' => 'active', 'purchased_at' => now()]);
        $foreignAdmin = User::create(['name' => 'F', 'email' => 'f@'.Str::lower(Str::random(8)).'.test', 'password' => bcrypt('pw'), 'tenant_id' => $foreign->id, 'role' => 'admin', 'onboarding_completed_at' => now()]);
        $this->actingAs($foreignAdmin, 'sanctum')->getJson("/api/sales/partners/{$id}")->assertNotFound();
        $this->actingAs($foreignAdmin, 'sanctum')->getJson('/api/sales/partners')->assertOk()->assertJsonPath('summary.total', 0);
    }

    public function test_import_endpoint_dry_run_and_role_gate(): void
    {
        $csv = (string) file_get_contents($this->csvFile([$this->rows()[0]]));

        $this->admin()->post('/api/sales/partners/import', [
            'file' => UploadedFile::fake()->createWithContent('shortlist.csv', $csv), 'dry_run' => 1,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.dry_run', true);
        $this->assertSame(0, PartnerProspect::forTenant($this->tenant->id)->count());

        $this->admin()->post('/api/sales/partners/import', [
            'file' => UploadedFile::fake()->createWithContent('shortlist.csv', $csv),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('summary.total', 1);
        $this->assertSame(1, PartnerProspect::forTenant($this->tenant->id)->count());

        $this->member()->post('/api/sales/partners/import', [
            'file' => UploadedFile::fake()->createWithContent('shortlist.csv', $csv),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_setting_an_email_validates_and_makes_the_prospect_sendable(): void
    {
        $this->import([$this->rows()[1]]);
        $prospect = PartnerProspect::forTenant($this->tenant->id)->firstOrFail();
        $this->assertSame(PartnerProspect::STATUS_NEEDS_EMAIL, $prospect->status);

        $this->admin()->patchJson("/api/sales/partners/{$prospect->id}", ['email' => 'nope'])->assertStatus(422);
        $this->admin()->patchJson("/api/sales/partners/{$prospect->id}", ['email' => 'noreply@brand.test'])
            ->assertStatus(422)->assertJsonPath('reason', 'role_address');

        $this->admin()->patchJson("/api/sales/partners/{$prospect->id}", ['email' => 'Hello@SMExaminer.test', 'notes' => 'Found in bio'])
            ->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.lead.email', 'hello@smexaminer.test');

        $prospect->refresh();
        $this->assertSame('operator', $prospect->email_source);
        $this->assertSame('Found in bio', $prospect->notes);
        $this->assertNotNull($prospect->next_send_at);
    }

    public function test_dm_queue_mark_sent_and_pasted_reply_stay_in_laravel(): void
    {
        $this->connectMailbox();
        $this->import([$this->rows()[1]]);
        app(OutreachSender::class)->runForTenant($this->tenant->refresh(), false, true);

        $queue = $this->admin()->getJson('/api/sales/partners/dm-queue')->assertOk()->assertJsonCount(1, 'data');
        $item = $queue->json('data.0');
        $this->assertSame('https://facebook.com/smexaminer', $item['prospect']['profile_url']);
        $this->assertStringContainsString('utm_content=', $item['body']);

        $this->admin()->postJson("/api/sales/partners/messages/{$item['message_id']}/mark-sent")->assertOk()
            ->assertJsonPath('data.status', 'sent')->assertJsonPath('prospect.status', 'dm_sent');
        $this->admin()->postJson("/api/sales/partners/messages/{$item['message_id']}/mark-sent")->assertStatus(409);
        $this->admin()->getJson('/api/sales/partners/dm-queue')->assertOk()->assertJsonCount(0, 'data');

        // The pasted reply must never reach the Python CRM agent (Redis bridge).
        Redis::shouldReceive('lpush')->never();

        $prospectId = $item['prospect']['id'];
        $this->admin()->postJson("/api/sales/partners/{$prospectId}/dm-reply", ['body' => 'Sounds interesting, how do I join?'])
            ->assertCreated()->assertJsonPath('prospect.status', 'replied')->assertJsonPath('data.direction', 'in');

        $prospect = PartnerProspect::forTenant($this->tenant->id)->findOrFail($prospectId);
        $this->assertNotNull($prospect->replied_at);
        $this->assertSame('engaged', $prospect->lead->stage);
        $inbound = ConversationMessage::forTenant($this->tenant->id)->where('direction', 'in')->firstOrFail();
        $this->assertSame('received', $inbound->status);
        $this->assertSame('reply', $inbound->classification);

        // event_log has no created_at (it records occurred_at + sequence_num).
        // sqlite reads an unknown double-quoted "created_at" as a string literal
        // and silently orders by a constant; Postgres rejects the column.
        $event = Event::where('tenant_id', $this->tenant->id)->where('event_type', 'conversation.message.received')
            ->orderByDesc('sequence_num')->get()
            ->first(fn (Event $e) => (($e->payload['message_id'] ?? null) === $inbound->id));
        $this->assertNotNull($event);
        $this->assertSame('laravel_outreach', $event->payload['bridge']);
        $this->assertSame($prospectId, $event->payload['prospect_id']);
    }

    public function test_settings_roundtrip_validation_and_manual_tick(): void
    {
        $this->admin()->getJson('/api/sales/partners/settings')->assertOk()
            ->assertJsonPath('data.program.commission_pct', 30)
            ->assertJsonPath('mailbox_connected', false)
            ->assertJsonPath('flags.sending', false);

        $this->admin()->putJson('/api/sales/partners/settings', [
            'program' => ['commission_pct' => 25, 'join_url' => 'https://x.affonso.io/?group=g2'],
            'replies' => ['mode' => 'auto'],
            'sending' => ['started_at' => '2020-01-01T00:00:00Z', 'per_run_cap' => 3],
        ])->assertOk()->assertJsonPath('data.program.commission_pct', 25)->assertJsonPath('data.replies.mode', 'auto');

        $settings = $this->admin()->getJson('/api/sales/partners/settings')->assertOk()->json('data');
        $this->assertSame(25, $settings['program']['commission_pct']);
        $this->assertSame(12, $settings['program']['months']);
        $this->assertSame(3, $settings['sending']['per_run_cap']);
        $this->assertNull($settings['sending']['started_at']);

        $this->admin()->putJson('/api/sales/partners/settings', ['replies' => ['mode' => 'yolo']])->assertStatus(422);
        $this->admin()->putJson('/api/sales/partners/settings', ['program' => ['join_url' => 'not a url']])->assertStatus(422);
        $this->member()->putJson('/api/sales/partners/settings', ['replies' => ['mode' => 'approve']])->assertForbidden();

        $this->admin()->postJson('/api/sales/partners/run', ['dry_run' => true, 'force' => true])->assertOk()
            ->assertJsonPath('data.dry_run', true);
    }

    public function test_public_unsubscribe_confirms_on_get_and_acts_on_post(): void
    {
        $this->import([$this->rows()[0]]);
        $prospect = PartnerProspect::forTenant($this->tenant->id)->firstOrFail();
        $url = '/api/public/outreach/unsubscribe/'.$prospect->invite_token;

        $this->get($url)->assertOk()->assertSee('<form', false)->assertSee('Unsubscribe');
        $this->assertSame(PartnerProspect::STATUS_READY, $prospect->refresh()->status);

        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('unsubscribed');
        $prospect->refresh();
        $this->assertSame(PartnerProspect::STATUS_UNSUBSCRIBED, $prospect->status);
        $this->assertNotNull($prospect->lead->consent['opted_out_at']);
        $this->assertDatabaseHas('consent_records', ['subject' => 'mike@example.test', 'status' => 'stopped', 'source' => 'unsubscribe_link']);
        $this->assertSame(1, ConsentRecord::forTenant($this->tenant->id)->where('status', 'stopped')->count());

        $this->post($url)->assertOk(); // idempotent
        $this->assertSame(1, ConsentRecord::forTenant($this->tenant->id)->where('status', 'stopped')->count());

        $this->get('/api/public/outreach/unsubscribe/x')->assertNotFound();
        $this->get('/api/public/outreach/unsubscribe/'.Str::random(22))->assertNotFound();
    }

    public function test_pause_resume_and_retire(): void
    {
        $this->import([$this->rows()[0]]);
        $prospect = PartnerProspect::forTenant($this->tenant->id)->firstOrFail();

        $this->admin()->postJson("/api/sales/partners/{$prospect->id}/pause")->assertOk();
        $this->assertNotNull($prospect->refresh()->bot_paused_at);
        $this->assertNull($prospect->next_send_at);

        $this->admin()->postJson("/api/sales/partners/{$prospect->id}/resume")->assertOk();
        $this->assertNull($prospect->refresh()->bot_paused_at);
        $this->assertNotNull($prospect->next_send_at);

        $this->admin()->postJson("/api/sales/partners/{$prospect->id}/retire")->assertOk()->assertJsonPath('data.status', 'retired');
        $this->admin()->postJson("/api/sales/partners/{$prospect->id}/retire")->assertStatus(409);
        $this->assertSame('recycled', $prospect->refresh()->lead->stage);
    }
}
