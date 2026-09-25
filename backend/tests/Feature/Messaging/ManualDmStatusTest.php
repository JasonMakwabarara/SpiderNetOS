<?php

namespace Tests\Feature\Messaging;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression: the manual-DM status is longer than the column used to allow.
 * On sqlite this always passed; on Postgres it threw 22001 and the whole DM
 * queue was dead. The assertion is the round-trip, so it guards both lanes.
 */
class ManualDmStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manual_dm_draft_status_round_trips(): void
    {
        $tenant = Tenant::create([
            'id' => (string) Str::uuid(),
            'name' => 'DM status tenant',
            'slug' => 'dm-status-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);

        $lead = Lead::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'name' => 'Creator One',
            'source' => 'outreach',
        ]);

        $conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'channel' => 'manual_dm',
            'status' => 'open',
        ]);

        $message = ConversationMessage::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'body' => 'Hi there - this is the DM draft an operator sends by hand.',
            'status' => 'awaiting_operator',
            'sent_by' => 'outreach',
        ]);

        $this->assertSame('awaiting_operator', $message->fresh()->status);
        $this->assertSame(1, ConversationMessage::forTenant($tenant->id)->where('status', 'awaiting_operator')->count());
    }
}
