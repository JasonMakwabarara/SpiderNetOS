<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Event;
use App\Models\Lead;
use App\Models\MessagingNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Messaging\OwnerNumberAllowlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * WhatsAppController used to create a Lead for any unknown From, so the
 * owner texting their own business number became a prospect (with a
 * conversation, a nurture pause and a crm dispatch). Owner/team numbers —
 * tenants.settings.whatsapp.owner_numbers or a user's preferences.phone —
 * must instead be routed to an owner.message.received event and never
 * touch the CRM.
 */
class WhatsAppOwnerAllowlistTest extends TestCase
{
    use RefreshDatabase;

    private const BUSINESS_NUMBER = '+15555550100';

    private const OWNER_NUMBER = '+263771234567';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Owner Co',
            'slug' => 'owner-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'growth',
            'automation_level' => 'manual',
            'onboarding_completed_at' => now(),
            'settings' => [],
        ]);

        MessagingNumber::create([
            'tenant_id' => $this->tenant->id,
            'channel' => 'whatsapp',
            'phone_number' => self::BUSINESS_NUMBER,
            'provider' => 'twilio',
            'is_active' => true,
        ]);
    }

    private function inbound(string $from, string $body = 'hello', string $sid = 'SM0001'): TestResponse
    {
        return $this->post('/api/whatsapp/inbound', [
            'From' => 'whatsapp:'.$from,
            'To' => 'whatsapp:'.self::BUSINESS_NUMBER,
            'Body' => $body,
            'MessageSid' => $sid,
        ]);
    }

    private function setOwnerNumbers(array $numbers): void
    {
        $this->tenant->settings = ['whatsapp' => ['owner_numbers' => $numbers]];
        $this->tenant->save();
    }

    public function test_owner_number_from_tenant_settings_is_routed_to_owner_event_not_a_lead(): void
    {
        $this->setOwnerNumbers([self::OWNER_NUMBER]);
        Redis::shouldReceive('lpush')->never();

        $this->inbound(self::OWNER_NUMBER, 'remind me to call Acme tomorrow')->assertStatus(200);

        $this->assertSame(0, Lead::forTenant((string) $this->tenant->id)->count());
        $this->assertSame(0, Conversation::forTenant((string) $this->tenant->id)->count());
        $this->assertSame(0, ConversationMessage::where('tenant_id', $this->tenant->id)->count());

        $event = Event::where('tenant_id', $this->tenant->id)->where('event_type', 'owner.message.received')->first();
        $this->assertNotNull($event, 'owner.message.received must be appended to the event log');
        $this->assertSame(self::OWNER_NUMBER, $event->payload['from']);
        $this->assertSame('whatsapp', $event->payload['channel']);
        $this->assertSame('remind me to call Acme tomorrow', $event->payload['body']);
        $this->assertSame('SM0001', $event->payload['provider_message_id']);

        $this->assertSame(0, Event::where('tenant_id', $this->tenant->id)->where('event_type', 'lead.imported')->count());
    }

    public function test_team_member_phone_in_user_preferences_is_treated_as_owner(): void
    {
        User::create([
            'name' => 'Ops',
            'email' => 'ops@'.Str::lower(Str::random(8)).'.test',
            'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id,
            'role' => 'admin',
            'preferences' => ['phone' => '+44 7700 900123'],
        ]);
        Redis::shouldReceive('lpush')->never();

        $this->inbound('+447700900123')->assertStatus(200);

        $this->assertSame(0, Lead::forTenant((string) $this->tenant->id)->count());
        $this->assertSame(1, Event::where('tenant_id', $this->tenant->id)->where('event_type', 'owner.message.received')->count());
    }

    public function test_owner_matching_ignores_formatting_and_prefixes(): void
    {
        $this->setOwnerNumbers(['whatsapp:+263 77 123 4567']);
        Redis::shouldReceive('lpush')->never();

        $this->inbound(self::OWNER_NUMBER)->assertStatus(200);

        $this->assertSame(0, Lead::forTenant((string) $this->tenant->id)->count());
        $this->assertSame(1, Event::where('tenant_id', $this->tenant->id)->where('event_type', 'owner.message.received')->count());
    }

    public function test_owner_path_wins_even_if_a_lead_row_already_exists_for_that_number(): void
    {
        // A lead created by the old bug must not keep swallowing the owner's messages.
        Lead::create([
            'tenant_id' => $this->tenant->id,
            'whatsapp_number' => self::OWNER_NUMBER,
            'source' => 'whatsapp_inbound',
            'stage' => 'captured',
            'score' => 20,
        ]);
        $this->setOwnerNumbers([self::OWNER_NUMBER]);
        Redis::shouldReceive('lpush')->never();

        $this->inbound(self::OWNER_NUMBER, 'STOP')->assertStatus(200);

        $this->assertSame(0, Conversation::forTenant((string) $this->tenant->id)->count());
        $this->assertSame(1, Event::where('tenant_id', $this->tenant->id)->where('event_type', 'owner.message.received')->count());
        // Not treated as a prospect opt-out either.
        $this->assertSame(0, Event::where('tenant_id', $this->tenant->id)->where('event_type', 'conversation.message.received')->count());
    }

    public function test_unknown_number_still_becomes_a_lead_with_a_conversation(): void
    {
        $this->setOwnerNumbers([self::OWNER_NUMBER]);
        Redis::shouldReceive('lpush')->zeroOrMoreTimes()->andReturn(1);

        $this->inbound('+15555559999', 'Hi, do you do web design?', 'SM0002')->assertStatus(200);

        $lead = Lead::forTenant((string) $this->tenant->id)->where('whatsapp_number', '+15555559999')->first();
        $this->assertNotNull($lead);
        $this->assertSame('whatsapp_inbound', $lead->source);
        $this->assertSame('engaged', $lead->stage);

        $this->assertSame(1, Conversation::forTenant((string) $this->tenant->id)->where('lead_id', $lead->id)->count());
        $this->assertSame(1, ConversationMessage::where('tenant_id', $this->tenant->id)->where('provider_message_id', 'SM0002')->count());
        $this->assertSame(0, Event::where('tenant_id', $this->tenant->id)->where('event_type', 'owner.message.received')->count());
    }

    public function test_allowlist_merges_settings_and_user_preferences(): void
    {
        $this->setOwnerNumbers(['+263771234567', '+1 (555) 000-1111']);
        User::create([
            'name' => 'Sales',
            'email' => 'sales@'.Str::lower(Str::random(8)).'.test',
            'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id,
            'role' => 'member',
            'preferences' => ['whatsapp_number' => '+27 82 555 0199', 'theme' => 'dark'],
        ]);

        $numbers = app(OwnerNumberAllowlist::class)->numbersFor((string) $this->tenant->id);

        $this->assertEqualsCanonicalizing(['263771234567', '15550001111', '27825550199'], $numbers);
        $this->assertTrue(app(OwnerNumberAllowlist::class)->isOwnerNumber((string) $this->tenant->id, 'whatsapp:+27825550199'));
        $this->assertFalse(app(OwnerNumberAllowlist::class)->isOwnerNumber((string) $this->tenant->id, '+15555559999'));
        $this->assertFalse(app(OwnerNumberAllowlist::class)->isOwnerNumber((string) $this->tenant->id, ''));
    }
}
