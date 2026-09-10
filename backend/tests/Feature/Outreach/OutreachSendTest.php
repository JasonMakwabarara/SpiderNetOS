<?php

declare(strict_types=1);

namespace Tests\Feature\Outreach;

use App\Mail\PartnerOutreachMail;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\PartnerProspect;
use App\Services\Outreach\OutreachSender;
use App\Services\Outreach\OutreachSettings;
use App\Services\Outreach\ProspectStateMachine;
use Illuminate\Support\Facades\Mail;

class OutreachSendTest extends OutreachTestCase
{
    private function readyProspect(string $handle = 'creator', string $email = 'creator@example.test'): PartnerProspect
    {
        $this->import([['name' => ucfirst($handle), 'domain' => "https://www.tiktok.com/@{$handle}", 'primary' => 'x', 'email' => $email]]);

        return PartnerProspect::forTenant($this->tenant->id)->where('handle', $handle)->firstOrFail();
    }

    private function tick(bool $force = true, bool $dryRun = false): array
    {
        return app(OutreachSender::class)->runForTenant($this->tenant->refresh(), $dryRun, $force);
    }

    public function test_flag_off_sends_nothing_and_force_sends_the_invite_as_the_tenant(): void
    {
        $this->connectMailbox();
        $prospect = $this->readyProspect();

        $this->assertSame('flag_off', $this->tick(force: false)['skipped']);
        Mail::assertNothingSent();

        $result = $this->tick();
        $this->assertSame(1, $result['sent'], json_encode($result));

        $prospect->refresh();
        $this->assertSame(PartnerProspect::STATUS_INVITED, $prospect->status);
        $this->assertSame(1, $prospect->sequence_step);
        $this->assertNotNull($prospect->last_sent_at);
        // Step 2 waits 4 days by default.
        $this->assertTrue($prospect->next_send_at->between(now()->addDays(4)->subMinute(), now()->addDays(4)->addMinute()));

        Mail::assertSent(PartnerOutreachMail::class, function (PartnerOutreachMail $mail) use ($prospect) {
            $rendered = $mail->render();

            return $mail->sender['address'] === 'partners@hannah-ai.test'
                && $mail->replyToAddress === 'partners+'.$prospect->invite_token.'@hannah-ai.test'
                && str_contains($mail->subjectLine, '30% recurring')
                && str_contains($mail->messageId, $prospect->invite_token.'@hannah-ai.test')
                && $mail->inReplyTo === null
                && str_contains($mail->bodyText, 'https://hannah.affonso.io/?group=grp1&utm_source=spidernet_outreach')
                && str_contains($mail->bodyText, 'utm_content='.$prospect->invite_token)
                && str_contains($rendered, '1 Test Street, Harare')
                && str_contains($rendered, '/api/public/outreach/unsubscribe/'.$prospect->invite_token)
                && ! str_contains($rendered, 'SpiderNet');
        });

        $conversation = Conversation::forTenant($this->tenant->id)->where('lead_id', $prospect->lead_id)->where('channel', 'email')->firstOrFail();
        $message = ConversationMessage::where('conversation_id', $conversation->id)->firstOrFail();
        $this->assertSame('sent', $message->status);
        $this->assertSame('partner.invite', $message->template_key);
        $this->assertSame('outreach', $message->sent_by);
        $this->assertNotNull($message->sent_at);
        $this->assertNotNull($message->subject);
        $this->assertStringContainsString($prospect->invite_token, (string) $message->message_id_header);
        $this->assertSame($message->message_id_header, $message->provider_message_id);

        // Nothing else is due today.
        $this->assertSame(0, $this->tick()['sent']);
    }

    public function test_sequence_threads_nudges_then_retires(): void
    {
        $this->connectMailbox();
        $prospect = $this->readyProspect();
        $this->tick();
        $first = ConversationMessage::forTenant($this->tenant->id)->where('direction', 'out')->firstOrFail();

        $this->travel(4)->days();
        $this->assertSame(1, $this->tick()['sent']);
        $prospect->refresh();
        $this->assertSame(PartnerProspect::STATUS_NUDGED, $prospect->status);
        Mail::assertSent(PartnerOutreachMail::class, fn (PartnerOutreachMail $m) => $m->inReplyTo === $first->message_id_header
            && $m->references === [$first->message_id_header]
            && str_starts_with($m->subjectLine, 'Re: '));

        $this->travel(6)->days();
        $this->assertSame(1, $this->tick()['sent']);
        $this->assertSame(PartnerProspect::STATUS_LAST_CALLED, $prospect->refresh()->status);
        $this->assertSame(3, $prospect->sequence_step);

        $this->travel(10)->days();
        $result = $this->tick();
        $this->assertSame(1, $result['retired']);
        $this->assertSame(0, $result['sent']);
        $this->assertSame(PartnerProspect::STATUS_RETIRED, $prospect->refresh()->status);
        $this->assertSame('recycled', $prospect->lead->stage);
        $this->assertSame(3, ConversationMessage::forTenant($this->tenant->id)->where('direction', 'out')->count());
    }

    public function test_gates_mailbox_quiet_hours_daily_cap_and_gap(): void
    {
        $prospect = $this->readyProspect();

        // No mailbox connected: nothing can go out as the tenant.
        $this->assertSame('no_mailbox', $this->tick()['skipped']);

        $this->connectMailbox();
        $settings = app(OutreachSettings::class);

        $settings->update($this->tenant, ['sending' => ['quiet_hours' => ['start' => '00:00', 'end' => '23:59']]]);
        $this->assertSame('quiet_hours', $this->tick()['skipped']);
        Mail::assertNothingSent();

        $settings->update($this->tenant, ['sending' => ['quiet_hours' => ['start' => '00:00', 'end' => '00:00'], 'warmup' => [['from_day' => 1, 'cap' => 1]]]]);
        $this->readyProspect('second', 'second@example.test');
        $this->assertSame(1, $this->tick()['sent']);
        $this->assertSame('daily_cap', $this->tick()['skipped']);
        Mail::assertSentCount(1);

        // Warm-up clock started with the first send.
        $this->assertNotNull($settings->for($this->tenant->refresh())['sending']['started_at']);

        // Day 2 with a higher rung and a send gap: one per tick, then min_gap.
        $settings->update($this->tenant, ['sending' => ['warmup' => [['from_day' => 1, 'cap' => 1], ['from_day' => 2, 'cap' => 5]], 'min_gap_seconds' => 600]]);
        $this->travel(1)->days();
        $this->readyProspect('third', 'third@example.test');
        $this->assertSame(1, $this->tick()['sent']);
        $this->assertSame('min_gap', $this->tick()['skipped']);
    }

    public function test_prospects_without_email_get_a_dm_draft_once(): void
    {
        $this->connectMailbox();
        $this->import([['name' => 'No Mail', 'domain' => 'https://www.tiktok.com/@nomail', 'primary' => 'x']]);
        $prospect = PartnerProspect::forTenant($this->tenant->id)->firstOrFail();
        $this->assertSame(PartnerProspect::STATUS_NEEDS_EMAIL, $prospect->status);

        $result = $this->tick();
        $this->assertSame(1, $result['drafted']);
        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();

        $prospect->refresh();
        $this->assertSame(PartnerProspect::STATUS_DM_DRAFTED, $prospect->status);
        $this->assertNotNull($prospect->dm_draft_at);

        $conversation = Conversation::forTenant($this->tenant->id)->where('lead_id', $prospect->lead_id)->where('channel', 'manual_dm')->firstOrFail();
        $draft = ConversationMessage::where('conversation_id', $conversation->id)->firstOrFail();
        $this->assertSame('awaiting_operator', $draft->status);
        $this->assertSame('partner.dm_invite', $draft->template_key);
        $this->assertStringContainsString('utm_content='.$prospect->invite_token, $draft->body);
        $this->assertStringContainsString('partners@hannah-ai.test', $draft->body);

        // A second tick does not queue a second draft.
        $this->assertSame(0, $this->tick()['drafted']);
        $this->assertSame(1, ConversationMessage::where('conversation_id', $conversation->id)->count());

        // An email found later moves the prospect onto the email sequence.
        $accepted = app(ProspectStateMachine::class)->acceptEmail($prospect, 'nomail@example.test', 'operator');
        $this->assertTrue($accepted['ok']);
        $this->assertSame(PartnerProspect::STATUS_READY, $prospect->refresh()->status);
        $this->assertSame(1, $this->tick()['sent']);
    }

    public function test_opt_out_and_bounce_stop_the_sequence(): void
    {
        $this->connectMailbox();
        $optedOut = $this->readyProspect('stopme', 'stopme@example.test');
        $bounced = $this->readyProspect('bouncer', 'bouncer@example.test');
        $lifecycle = app(ProspectStateMachine::class);

        $lifecycle->optOut($optedOut, 'unsubscribe_link');
        $lifecycle->markBounced($bounced, '550 5.1.1 user unknown');

        $this->assertSame(PartnerProspect::STATUS_UNSUBSCRIBED, $optedOut->refresh()->status);
        $this->assertNotNull($optedOut->lead->consent['opted_out_at']);
        $this->assertSame('lost', $optedOut->lead->stage);
        $this->assertDatabaseHas('consent_records', ['subject' => 'stopme@example.test', 'status' => 'stopped']);
        $this->assertSame(PartnerProspect::STATUS_BOUNCED, $bounced->refresh()->status);
        $this->assertSame('550 5.1.1 user unknown', $bounced->bounce_reason);

        $result = $this->tick();
        $this->assertSame(0, $result['sent']);
        Mail::assertNothingSent();

        // An opted-out address can never be re-accepted, even by an operator.
        $again = $lifecycle->acceptEmail($optedOut, 'stopme@example.test', 'operator');
        $this->assertSame('opted_out', $again['reason']);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $this->connectMailbox();
        $this->readyProspect();
        $this->import([['name' => 'No Mail', 'domain' => 'https://www.tiktok.com/@nomail', 'primary' => 'x']]);

        $result = $this->tick(dryRun: true);
        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['drafted']);
        Mail::assertNothingSent();
        $this->assertSame(0, ConversationMessage::forTenant($this->tenant->id)->count());
        $this->assertSame(1, PartnerProspect::forTenant($this->tenant->id)->where('status', PartnerProspect::STATUS_READY)->count());

        $this->artisan('outreach:send-due', ['tenant' => $this->tenant->slug, '--dry-run' => true, '--force' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();
    }
}
