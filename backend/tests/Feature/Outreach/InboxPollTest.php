<?php

declare(strict_types=1);

namespace Tests\Feature\Outreach;

use App\Mail\PartnerOutreachMail;
use App\Models\ConversationMessage;
use App\Models\Event;
use App\Models\PartnerProspect;
use App\Services\Outreach\Inbound\ImapMailboxReader;
use App\Services\Outreach\Inbound\InboxPoller;
use App\Services\Outreach\OutreachSender;
use App\Services\Outreach\OutreachSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Tests\Feature\Outreach\Support\FakeImapReader;

class InboxPollTest extends OutreachTestCase
{
    private FakeImapReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new FakeImapReader;
        $this->app->instance(ImapMailboxReader::class, $this->reader);
    }

    /** Import one creator with an email and send the invite so a thread exists. */
    private function invited(string $handle = 'creator', string $email = 'creator@example.test'): PartnerProspect
    {
        $this->import([['name' => ucfirst($handle), 'domain' => "https://www.tiktok.com/@{$handle}", 'primary' => 'x', 'email' => $email]]);
        app(OutreachSender::class)->runForTenant($this->tenant->refresh(), false, true);

        return PartnerProspect::forTenant($this->tenant->id)->where('handle', $handle)->firstOrFail();
    }

    private function poll(bool $dryRun = false): array
    {
        return app(InboxPoller::class)->pollTenant($this->tenant->refresh(), $dryRun, true);
    }

    private function sentMessageId(PartnerProspect $prospect): string
    {
        return (string) ConversationMessage::forTenant($this->tenant->id)->where('direction', 'out')
            ->whereHas('conversation', fn ($q) => $q->where('lead_id', $prospect->lead_id))->value('message_id_header');
    }

    public function test_gates_flag_and_imap_credentials(): void
    {
        $this->connectMailbox(withImap: false);
        $this->assertSame('flag_off', app(InboxPoller::class)->pollTenant($this->tenant, false, false)['skipped']);
        $this->assertSame('no_imap', $this->poll()['skipped']);
        $this->assertSame([], $this->reader->fetches);
    }

    public function test_plus_address_reply_is_stored_matched_and_kept_in_laravel(): void
    {
        $this->connectMailbox(withImap: true);
        $prospect = $this->invited();
        Redis::shouldReceive('lpush')->never();

        $this->reader->messages = [FakeImapReader::message([
            'uid' => 41, 'messageId' => 'reply-1@creator.test', 'from' => 'other-alias@creator.test',
            'recipients' => ['partners+'.$prospect->invite_token.'@hannah-ai.test'],
            'textBody' => "Sounds good, how do I get paid?\n\nOn Tue, Hannah AI Partnerships wrote:\n> Hi Creator",
        ])];

        $result = $this->poll();
        $this->assertSame(1, $result['fetched']);
        $this->assertSame(['reply' => 1], $result['actions']);
        $this->assertSame(41, $result['last_uid']);

        $prospect->refresh();
        $this->assertSame(PartnerProspect::STATUS_REPLIED, $prospect->status);
        $this->assertNotNull($prospect->replied_at);
        $this->assertNull($prospect->next_send_at);
        $this->assertSame('engaged', $prospect->lead->stage);

        $inbound = ConversationMessage::forTenant($this->tenant->id)->where('direction', 'in')->firstOrFail();
        $this->assertSame('Sounds good, how do I get paid?', $inbound->body);
        $this->assertSame('reply', $inbound->classification);
        $this->assertSame('reply-1@creator.test', $inbound->message_id_header);
        $this->assertSame('other-alias@creator.test', $inbound->headers['from']);
        $this->assertSame('pending', $inbound->conversation->status);

        $event = Event::where('tenant_id', $this->tenant->id)->where('event_type', 'conversation.message.received')->get()
            ->first(fn (Event $e) => ($e->payload['message_id'] ?? null) === $inbound->id);
        $this->assertNotNull($event);
        $this->assertSame('laravel_outreach', $event->payload['bridge']);
        $this->assertSame('reply', $event->payload['classification']);

        // The cursor moved on and a re-poll of the same message is a no-op.
        $this->assertSame(41, app(OutreachSettings::class)->for($this->tenant->refresh())['mailbox']['imap_last_uid']);
        $this->reader->messages[] = FakeImapReader::message(['uid' => 42, 'messageId' => 'reply-1@creator.test', 'from' => 'creator@example.test']);
        $again = $this->poll();
        $this->assertSame(41, $this->reader->fetches[1]['after_uid']);
        $this->assertSame(['duplicate' => 1], $again['actions']);
        $this->assertSame(1, ConversationMessage::forTenant($this->tenant->id)->where('direction', 'in')->count());
    }

    public function test_matches_by_threading_headers_then_by_sender(): void
    {
        $this->connectMailbox(withImap: true);
        $prospect = $this->invited();
        $sentId = $this->sentMessageId($prospect);

        $this->reader->messages = [
            FakeImapReader::message(['uid' => 1, 'messageId' => 'a@x.test', 'from' => 'agent@creator-management.test', 'recipients' => ['partners@hannah-ai.test'], 'inReplyTo' => [$sentId], 'references' => [$sentId], 'textBody' => 'Replying for my client.']),
            FakeImapReader::message(['uid' => 2, 'messageId' => 'b@x.test', 'from' => 'creator@example.test', 'recipients' => ['partners@hannah-ai.test'], 'textBody' => 'Fresh email, no headers.']),
            FakeImapReader::message(['uid' => 3, 'messageId' => 'c@x.test', 'from' => 'stranger@nowhere.test', 'recipients' => ['partners@hannah-ai.test'], 'textBody' => 'Who are you?']),
            FakeImapReader::message(['uid' => 4, 'messageId' => 'd@x.test', 'from' => 'partners@hannah-ai.test', 'recipients' => ['someone@x.test'], 'textBody' => 'Our own outbound copy.']),
        ];

        $result = $this->poll();
        $this->assertSame(['reply' => 2, 'unmatched' => 1, 'own' => 1], $result['actions']);
        $this->assertSame(2, ConversationMessage::forTenant($this->tenant->id)->where('direction', 'in')->count());
        $this->assertSame(4, $result['last_uid']);
    }

    public function test_bounce_auto_reply_and_stop_have_the_right_effects(): void
    {
        $this->connectMailbox(withImap: true);
        $bouncer = $this->invited('bouncer', 'bouncer@example.test');
        $away = $this->invited('away', 'away@example.test');
        $stopper = $this->invited('stopper', 'stopper@example.test');
        $bounceRaw = "Final-Recipient: rfc822; bouncer@example.test\nAction: failed\nStatus: 5.1.1\nDiagnostic-Code: smtp; 550 5.1.1 user unknown\n\nContent-Type: message/rfc822\nMessage-ID: <".$this->sentMessageId($bouncer).'>';

        $this->reader->messages = [
            FakeImapReader::message(['uid' => 1, 'messageId' => 'dsn@mx.test', 'from' => 'mailer-daemon@mx.zoho.com', 'subject' => 'Undelivered Mail Returned to Sender', 'headers' => ['content-type' => 'multipart/report; report-type=delivery-status'], 'textBody' => 'Delivery failed', 'rawBody' => $bounceRaw]),
            FakeImapReader::message(['uid' => 2, 'messageId' => 'ooo@x.test', 'from' => 'away@example.test', 'subject' => 'Automatic reply: Partnering', 'headers' => ['auto-submitted' => 'auto-replied'], 'textBody' => 'I am out until Monday.']),
            FakeImapReader::message(['uid' => 3, 'messageId' => 'stop@x.test', 'from' => 'stopper@example.test', 'textBody' => 'Please unsubscribe me, not interested.']),
        ];

        Redis::shouldReceive('lpush')->never();
        $result = $this->poll();
        $this->assertSame(['bounce' => 1, 'auto_reply' => 1, 'opt_out' => 1], $result['actions']);

        $this->assertSame(PartnerProspect::STATUS_BOUNCED, $bouncer->refresh()->status);
        $this->assertStringContainsString('user unknown', (string) $bouncer->bounce_reason);
        $this->assertSame('lost', $bouncer->lead->stage);

        $this->assertSame(PartnerProspect::STATUS_INVITED, $away->refresh()->status, 'an auto-responder must not count as a reply');
        $this->assertNotNull($away->next_send_at);
        $this->assertSame(1, ConversationMessage::forTenant($this->tenant->id)->where('classification', 'auto_reply')->count());

        $this->assertSame(PartnerProspect::STATUS_UNSUBSCRIBED, $stopper->refresh()->status);
        $this->assertNotNull($stopper->lead->consent['opted_out_at']);
        $this->assertDatabaseHas('consent_records', ['subject' => 'stopper@example.test', 'status' => 'stopped', 'source' => 'inbound_stop']);
        $this->assertSame(0, Event::where('tenant_id', $this->tenant->id)->where('event_type', 'conversation.message.received')->get()
            ->filter(fn (Event $e) => ($e->payload['prospect_id'] ?? null) === $stopper->id)->count(), 'STOP never wakes the bot');

        // Later ticks: the bounced and unsubscribed prospects are left alone, the
        // auto-responder was not a reply so "away" still gets its nudge.
        $this->travel(5)->days();
        $this->assertSame(1, app(OutreachSender::class)->runForTenant($this->tenant->refresh(), false, true)['sent']);
        $this->assertSame(PartnerProspect::STATUS_NUDGED, $away->refresh()->status);
        $this->assertSame(PartnerProspect::STATUS_BOUNCED, $bouncer->refresh()->status);
        $this->assertSame(PartnerProspect::STATUS_UNSUBSCRIBED, $stopper->refresh()->status);
    }

    public function test_dry_run_and_command(): void
    {
        $this->connectMailbox(withImap: true);
        $prospect = $this->invited();
        $this->reader->messages = [FakeImapReader::message(['uid' => 9, 'recipients' => ['partners+'.$prospect->invite_token.'@hannah-ai.test']])];

        $result = $this->poll(dryRun: true);
        $this->assertSame(['reply' => 1], $result['actions']);
        $this->assertSame(0, ConversationMessage::forTenant($this->tenant->id)->where('direction', 'in')->count());
        $this->assertSame(PartnerProspect::STATUS_INVITED, $prospect->refresh()->status);
        $this->assertNull(app(OutreachSettings::class)->for($this->tenant->refresh())['mailbox']['imap_last_uid']);

        $this->artisan('outreach:poll-inbox', ['tenant' => $this->tenant->slug, '--dry-run' => true, '--force' => true])
            ->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->artisan('outreach:poll-inbox', ['tenant' => 'nope'])->assertFailed();
    }

    public function test_operator_reply_threads_into_the_conversation(): void
    {
        $this->connectMailbox(withImap: true);
        $prospect = $this->invited();
        $this->reader->messages = [FakeImapReader::message(['uid' => 5, 'messageId' => 'q@creator.test', 'from' => 'creator@example.test', 'subject' => 'Re: Partnering with Hannah AI: 30% recurring for your audience', 'textBody' => 'How do I get paid?'])];
        $this->poll();

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/sales/partners/{$prospect->id}/reply", ['body' => 'Monthly via Affonso once you pass the minimum.'])
            ->assertCreated()->assertJsonPath('prospect.status', 'negotiating');

        Mail::assertSent(PartnerOutreachMail::class, fn (PartnerOutreachMail $m) => $m->inReplyTo === 'q@creator.test'
            && in_array('q@creator.test', $m->references, true)
            && $m->subjectLine === 'Re: Partnering with Hannah AI: 30% recurring for your audience'
            && str_contains($m->bodyText, 'Monthly via Affonso'));

        $out = ConversationMessage::forTenant($this->tenant->id)->where('direction', 'out')->orderByDesc('created_at')->first();
        $this->assertSame('q@creator.test', $out->in_reply_to);
        $this->assertSame((string) $this->admin->id, $out->sent_by);

        // A DM draft from the thread view lands in the operator queue.
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/sales/partners/{$prospect->id}/reply", ['body' => 'Hey, sent you an email!', 'channel' => 'manual_dm'])
            ->assertCreated()->assertJsonPath('data.status', 'awaiting_operator');
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/sales/partners/dm-queue')->assertOk()->assertJsonCount(1, 'data');
    }
}
