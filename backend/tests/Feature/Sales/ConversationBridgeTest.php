<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\EventStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * C4: ConversationReplyBridgeProjection — appending a
 * conversation.message.received event through the real EventStore pushes one
 * well-formed dispatch job onto the Redis agent:dispatch list (the envelope
 * intelligence/main.py's BLPOP consumer requires), gated on the tenant's
 * automation_level.
 *
 * Laravel has no Redis::fake(), so the Redis facade root is mocked with
 * shouldReceive() and the pushed payload captured for assertion.
 */
class ConversationBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(string $automationLevel): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Bridge Co',
            'slug' => 'bridge-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'automation_level' => $automationLevel,
            'onboarding_completed_at' => now(),
        ]);
    }

    /**
     * @return array{0: Lead, 1: Conversation, 2: ConversationMessage}
     */
    private function seedConversation(Tenant $tenant): array
    {
        $lead = Lead::create([
            'tenant_id' => $tenant->id,
            'source' => 'whatsapp_inbound',
            'stage' => 'engaged',
            'score' => 20,
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'channel' => 'whatsapp',
            'status' => 'open',
        ]);

        $message = ConversationMessage::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'body' => 'Tell me more about pricing',
            'status' => 'delivered',
        ]);

        return [$lead, $conversation, $message];
    }

    public function test_message_received_event_pushes_one_well_formed_dispatch_job(): void
    {
        $tenant = $this->createTenant('assisted');
        [$lead, $conversation, $message] = $this->seedConversation($tenant);

        $pushedQueue = null;
        $pushedJob = null;
        Redis::shouldReceive('lpush')
            ->once()
            ->andReturnUsing(function ($queue, $job) use (&$pushedQueue, &$pushedJob) {
                $pushedQueue = $queue;
                $pushedJob = $job;

                return 1;
            });

        app(EventStore::class)->append(
            (string) $tenant->id,
            'conversation',
            $conversation->id,
            'conversation.message.received',
            ['lead_id' => $lead->id, 'channel' => 'whatsapp', 'message_id' => $message->id],
        );

        $this->assertSame('agent:dispatch', $pushedQueue);

        $job = json_decode((string) $pushedJob, true);
        $this->assertIsArray($job);
        // Envelope keys the intelligence worker requires (see
        // intelligence/main.py _process_message: tenant_id/agent_id/intent/context).
        $this->assertSame((string) $tenant->id, $job['tenant_id']);
        $this->assertSame('sales_crm_crm', $job['agent_id']);
        $this->assertSame('conversation_reply', $job['intent']);
        $this->assertSame($lead->id, $job['context']['lead_id']);
        $this->assertSame($conversation->id, $job['context']['conversation_id']);
        $this->assertSame($message->id, $job['context']['message_id']);
        $this->assertSame('whatsapp', $job['context']['channel']);
        $this->assertNotEmpty($job['id'] ?? null);
        $this->assertNotEmpty($job['event_id'] ?? null);

        // The durable record still landed regardless of dispatch.
        $this->assertDatabaseHas('event_log', [
            'tenant_id' => (string) $tenant->id,
            'event_type' => 'conversation.message.received',
        ]);
    }

    public function test_manual_automation_level_skips_dispatch_but_keeps_the_event(): void
    {
        $tenant = $this->createTenant('manual');
        [$lead, $conversation] = $this->seedConversation($tenant);

        Redis::shouldReceive('lpush')->never();

        app(EventStore::class)->append(
            (string) $tenant->id,
            'conversation',
            $conversation->id,
            'conversation.message.received',
            ['lead_id' => $lead->id, 'channel' => 'whatsapp'],
        );

        $this->assertDatabaseHas('event_log', [
            'tenant_id' => (string) $tenant->id,
            'event_type' => 'conversation.message.received',
        ]);
    }

    public function test_message_id_recovered_from_conversation_when_absent_from_payload(): void
    {
        $tenant = $this->createTenant('autonomous');
        [$lead, $conversation, $message] = $this->seedConversation($tenant);

        $pushedJob = null;
        Redis::shouldReceive('lpush')
            ->once()
            ->andReturnUsing(function ($queue, $job) use (&$pushedJob) {
                $pushedJob = $job;

                return 1;
            });

        // Producers like the WhatsApp webhook only carry lead_id + channel.
        app(EventStore::class)->append(
            (string) $tenant->id,
            'conversation',
            $conversation->id,
            'conversation.message.received',
            ['lead_id' => $lead->id, 'channel' => 'whatsapp'],
        );

        $job = json_decode((string) $pushedJob, true);
        $this->assertSame($message->id, $job['context']['message_id']);
    }
}
