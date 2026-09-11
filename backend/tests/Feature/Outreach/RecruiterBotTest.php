<?php

declare(strict_types=1);

namespace Tests\Feature\Outreach;

use App\Mail\PartnerOutreachMail;
use App\Models\ConsentRecord;
use App\Models\ConversationMessage;
use App\Models\Event;
use App\Models\PartnerProspect;
use App\Services\Outreach\Inbound\ImapMailboxReader;
use App\Services\Outreach\Inbound\InboxPoller;
use App\Services\Outreach\OutreachSender;
use App\Services\Outreach\OutreachSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Outreach\Support\FakeImapReader;

/**
 * The recruiter bot end to end: a creator's reply is polled in, the projection
 * queues the draft job (sync queue in tests), the bot asks the (faked)
 * inference plane, and the outcome is an approval, a sent email or a handoff.
 */
class RecruiterBotTest extends OutreachTestCase
{
    private FakeImapReader $reader;

    private ?string $completion = null;

    private int $modelStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new FakeImapReader;
        $this->app->instance(ImapMailboxReader::class, $this->reader);
        $this->connectMailbox(withImap: true);
        $this->flags(['outreach.bot_replies' => 'on']);

        // One fake for the whole test: Laravel keeps every Http::fake() stub and the first
        // match wins, so re-faking inside a test would never override an earlier stub.
        Http::fake([
            '*/generate' => function () {
                if ($this->completion === null) {
                    return Http::response('no completion staged for this test', 500);
                }
                if ($this->modelStatus !== 200) {
                    return Http::response('inference plane unavailable', $this->modelStatus);
                }

                return Http::response(['text' => $this->completion, 'model' => 'deepseek-v4-flash', 'tokens_used' => 120, 'cost' => 0.0004, 'provider' => 'modelark']);
            },
            'api.affonso.io/*' => fn (Request $request) => $request->method() === 'POST'
                ? Http::response(['data' => ['id' => 'aff_9', 'tracking_id' => 'creator', 'partnership_status' => 'ACTIVE'] + $request->data()], 201)
                : Http::response(['data' => []]),
        ]);
    }

    private function flags(array $values): void
    {
        config()->set('features', array_merge((array) config('features'), $values));
        Cache::flush();
    }

    private function invited(string $handle = 'creator', string $email = 'creator@example.test'): PartnerProspect
    {
        $this->import([['name' => ucfirst($handle), 'domain' => "https://www.tiktok.com/@{$handle}", 'primary' => 'x', 'email' => $email]]);
        app(OutreachSender::class)->runForTenant($this->tenant->refresh(), false, true);

        return PartnerProspect::forTenant($this->tenant->id)->where('handle', $handle)->firstOrFail();
    }

    /** Stage the inference plane's next completion (JSON object or raw text). */
    private function model(array|string $completion): void
    {
        $this->modelStatus = 200;
        $this->completion = is_string($completion)
            ? $completion
            : (string) json_encode($completion + ['extracted' => ['email' => null, 'country' => null, 'handle' => null], 'confidence' => 0.9]);
    }

    private function modelDown(): void
    {
        $this->modelStatus = 503;
    }

    /** Poll one reply from the prospect into the tenant mailbox. UIDs must increase per thread. */
    private function inbound(PartnerProspect $prospect, string $text, int $uid = 1): void
    {
        $this->reader->messages = [FakeImapReader::message([
            'uid' => $uid, 'messageId' => "m{$uid}-{$prospect->handle}@creator.test", 'from' => (string) $prospect->lead->email,
            'recipients' => ['partners+'.$prospect->invite_token.'@hannah-ai.test'], 'textBody' => $text,
        ])];
        app(InboxPoller::class)->pollTenant($this->tenant->refresh(), false, true);
    }

    private function draft(): ?ConversationMessage
    {
        return ConversationMessage::forTenant($this->tenant->id)->where('sent_by', 'recruiter_bot')
            ->where('status', '!=', 'sent')->orderByDesc('created_at')->first();
    }

    private function approval(string $resourceType, ?string $status = null): ?object
    {
        return DB::table('approvals')->where('tenant_id', $this->tenant->id)->where('resource_type', $resourceType)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')->first();
    }

    private function context(object $approval): array
    {
        return (array) json_decode((string) $approval->context, true);
    }

    private function approve(string $approvalId, bool $grant = true, ?string $reason = null): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/approvals/'.$approvalId.($grant ? '/approve' : '/reject'), array_filter(['reason' => $reason]))
            ->assertOk();
    }

    public function test_approve_mode_drafts_a_reply_and_raises_an_approval(): void
    {
        $prospect = $this->invited();
        $this->model([
            'reply' => 'Happy to explain: 30% recurring for 12 months, paid monthly from $50. Join here: https://hannah.affonso.io/?group=grp1',
            'action' => 'none', 'extracted' => ['email' => null, 'country' => 'UK', 'handle' => null],
        ]);

        $this->inbound($prospect, 'Sounds good, how do I get paid?');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/generate')
            && $r['temperature'] === 0.3 && $r['max_tokens'] === 600
            && str_contains((string) $r['system_prompt'], '30% of net payments')
            && str_contains((string) $r['prompt'], 'how do I get paid'));

        $draft = $this->draft();
        $this->assertNotNull($draft);
        $this->assertSame('draft', $draft->status);
        $this->assertSame('none', $draft->draft_action);
        $this->assertSame('recruiter-v1', $draft->draft_meta['prompt_version']);
        $this->assertSame('UK', $draft->draft_meta['extracted']['country']);

        $approval = $this->approval('outreach_reply');
        $this->assertSame($draft->id, $approval->resource_id);
        $this->assertSame('outreach_reply', $approval->approval_type);
        $this->assertSame('pending', $approval->status);
        $context = $this->context($approval);
        $this->assertSame($draft->body, $context['draft_body']);
        $this->assertSame($prospect->id, $context['prospect_id']);
        $this->assertSame('low', $context['risk']);
        $this->assertStringContainsString('how do I get paid', $context['inbound_excerpt']);

        Mail::assertSent(PartnerOutreachMail::class, 1); // only the invite so far
        $prospect->refresh();
        $this->assertSame(PartnerProspect::STATUS_REPLIED, $prospect->status);
        $this->assertSame(1, $prospect->bot_replies_today);
        $this->assertSame($draft->draft_meta['inbound_message_id'], $prospect->reply_claim_message_id);
    }

    public function test_approving_sends_the_edited_body_in_thread_and_applies_the_action(): void
    {
        $prospect = $this->invited();
        $this->model(['reply' => 'Brilliant, welcome aboard!', 'action' => 'signed_up']);
        $this->inbound($prospect, 'Done, I just signed up through the link.');
        $draft = $this->draft();
        $approval = $this->approval('outreach_reply');

        $edited = 'Brilliant, welcome aboard! Your dashboard is in the portal.';
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/sales/partners/drafts/{$draft->id}", ['body' => $edited])->assertOk();
        $this->assertSame($edited, $this->context(DB::table('approvals')->where('id', $approval->id)->first())['draft_body']);

        $this->approve($approval->id);

        Mail::assertSent(PartnerOutreachMail::class, 2);
        Mail::assertSent(PartnerOutreachMail::class, fn (PartnerOutreachMail $m) => $m->bodyText === $edited
            && $m->inReplyTo !== null && str_starts_with($m->subjectLine, 'Re:'));

        $draft->refresh();
        $this->assertSame('approved', $draft->status);
        $this->assertSame($edited, $draft->body);
        $sent = ConversationMessage::findOrFail($draft->draft_meta['sent_message_id']);
        $this->assertSame('sent', $sent->status);
        $this->assertSame('recruiter_bot', $sent->sent_by);
        $this->assertSame($edited, $sent->body);

        $prospect->refresh();
        $this->assertSame(PartnerProspect::STATUS_SIGNED_UP, $prospect->status);
        $this->assertNotNull($prospect->signed_up_at);
        $this->assertNull($prospect->next_send_at);
        $this->assertSame('won', $prospect->lead->stage);
        // Nobody has confirmed an affiliate exists yet, so it stays flagged for a human.
        $this->assertSame('verify_signup', $prospect->needs_human_reason);
        $this->assertNotNull($prospect->needs_human_at);

        // Resolved drafts are frozen.
        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/sales/partners/drafts/{$draft->id}", ['body' => 'x'])->assertStatus(409);
    }

    public function test_rejecting_sends_nothing_and_flags_a_human(): void
    {
        $prospect = $this->invited();
        $this->model(['reply' => 'Sure, the published rate is 30% for 12 months.', 'action' => 'none']);
        $this->inbound($prospect, 'Can you do better than the standard rate?');
        $approval = $this->approval('outreach_reply');

        $this->approve($approval->id, false, 'Too salesy');

        Mail::assertSent(PartnerOutreachMail::class, 1);
        $draft = $this->draft();
        $this->assertSame('rejected', $draft->status);
        $this->assertSame('Too salesy', $draft->draft_meta['rejected_reason']);
        $prospect->refresh();
        $this->assertSame('draft_rejected', $prospect->needs_human_reason);
        $this->assertSame(PartnerProspect::STATUS_REPLIED, $prospect->status);
    }

    public function test_sensitive_messages_hand_off_before_the_model_and_can_be_handed_back(): void
    {
        $prospect = $this->invited();

        $this->inbound($prospect, 'Where did you get my email?? This is spam and my lawyer will hear about it.');

        Http::assertNothingSent();
        $this->assertNull($this->draft());
        $prospect->refresh();
        $this->assertSame(PartnerProspect::STATUS_HANDOFF, $prospect->status);
        $this->assertNotNull($prospect->bot_paused_at);
        $this->assertSame('sensitive_topic', $prospect->needs_human_reason);
        $escalation = $this->approval('outreach_thread');
        $this->assertSame('escalation', $escalation->approval_type);
        $this->assertSame('sensitive_topic', $this->context($escalation)['reason']);
        $this->assertStringContainsString('lawyer', $this->context($escalation)['inbound_excerpt']);

        // While parked, further messages are stored but never reach the model.
        $this->model(['reply' => 'Hello again', 'action' => 'none']);
        $this->inbound($prospect, 'Hello? Anyone there?', 2);
        Http::assertNothingSent();
        $this->assertSame(2, ConversationMessage::forTenant($this->tenant->id)->where('direction', 'in')->count());

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/sales/partners/{$prospect->id}/hand-back")
            ->assertOk()->assertJsonPath('data.status', 'negotiating');
        $prospect->refresh();
        $this->assertNull($prospect->bot_paused_at);
        $this->assertNull($prospect->needs_human_at);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/sales/partners/{$prospect->id}/hand-back")->assertStatus(409);
    }

    public function test_filter_failures_and_model_errors_become_handoffs(): void
    {
        $first = $this->invited('first', 'first@example.test');
        $this->model(['reply' => 'We pay 50% commission!', 'action' => 'none']);
        $this->inbound($first, 'What is the commission?');

        $this->assertNull($this->draft());
        $this->assertSame(PartnerProspect::STATUS_HANDOFF, $first->refresh()->status);
        $this->assertSame('unverified_figure', $first->needs_human_reason);
        $this->assertSame('We pay 50% commission!', $this->context($this->approval('outreach_thread'))['model_reply']);

        $second = $this->invited('second', 'second@example.test');
        $this->modelDown();
        $this->inbound($second, 'Is this still open?', 2);

        $this->assertSame(PartnerProspect::STATUS_HANDOFF, $second->refresh()->status);
        $this->assertSame('llm_error', $second->needs_human_reason);
        Mail::assertSent(PartnerOutreachMail::class, 2); // the two invites, nothing from the bot
    }

    public function test_auto_mode_sends_immediately_and_the_thread_cap_hands_off(): void
    {
        app(OutreachSettings::class)->update($this->tenant, ['replies' => ['mode' => 'auto', 'per_thread_daily_cap' => 1]]);
        $prospect = $this->invited();
        $this->model(['reply' => 'Yes, 30% recurring for 12 months. Join: https://hannah.affonso.io/?group=grp1', 'action' => 'none']);

        $this->inbound($prospect, 'Is it recurring?');

        Mail::assertSent(PartnerOutreachMail::class, 2);
        Mail::assertSent(PartnerOutreachMail::class, fn (PartnerOutreachMail $m) => str_starts_with($m->bodyText, 'Yes, 30% recurring'));
        $draft = $this->draft();
        $this->assertSame('approved', $draft->status);
        $this->assertSame('auto', $draft->draft_meta['mode']);
        $this->assertNull($this->approval('outreach_reply'));
        $this->assertSame(PartnerProspect::STATUS_NEGOTIATING, $prospect->refresh()->status);

        // A second message the same day is over the per-thread cap: no model call, a human is asked.
        $this->inbound($prospect, 'And how do payouts work?', 2);
        Mail::assertSent(PartnerOutreachMail::class, 2);
        $this->assertSame(PartnerProspect::STATUS_HANDOFF, $prospect->refresh()->status);
        $this->assertSame('thread_daily_cap', $prospect->needs_human_reason);
        $this->assertCount(1, Http::recorded());
    }

    public function test_with_the_flag_off_the_thread_is_left_to_humans(): void
    {
        $this->flags(['outreach.bot_replies' => 'off']);
        $prospect = $this->invited();

        $this->inbound($prospect, 'Tell me more');

        Http::assertNothingSent();
        $this->assertNull($this->draft());
        $prospect->refresh();
        $this->assertSame(PartnerProspect::STATUS_REPLIED, $prospect->status);
        $this->assertNull($prospect->reply_claim_message_id);
    }

    public function test_create_affiliate_hands_off_until_affonso_actions_are_on_then_creates_the_affiliate(): void
    {
        // Flag off: the reply still goes out, the account creation waits for a human.
        $first = $this->invited('first', 'first@example.test');
        $this->model(['reply' => 'Done, I have set your account up; check your inbox.', 'action' => 'create_affiliate']);
        $this->inbound($first, 'Please set me up with first@example.test');
        $approval = $this->approval('outreach_reply', 'pending');
        $this->assertSame('high', $this->context($approval)['risk']);
        $this->approve($approval->id);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'affonso'));
        $first->refresh();
        $this->assertSame(PartnerProspect::STATUS_NEGOTIATING, $first->status);
        $this->assertSame('affonso_actions_off', $first->needs_human_reason);

        // Flag on + connector: the affiliate is created through the API and the prospect is signed up.
        $this->flags(['outreach.affonso_actions' => 'on']);
        $this->connectAffonso();
        $second = $this->invited('second', 'second@example.test');
        $this->model(['reply' => 'Done, I have set your account up; check your inbox.', 'action' => 'create_affiliate']);
        $this->inbound($second, 'Yes please create it for me', 2);
        $this->approve($this->approval('outreach_reply', 'pending')->id);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/affiliates')
            && $r['email'] === 'second@example.test' && $r['external_user_id'] === $second->lead_id
            && $r['group_id'] === 'grp_1' && $r['metadata']['prospect_token'] === $second->invite_token);
        $second->refresh();
        $this->assertSame('aff_9', $second->affonso_affiliate_id);
        $this->assertSame('ACTIVE', $second->affiliate_status);
        $this->assertSame(PartnerProspect::STATUS_SIGNED_UP, $second->status);
        $this->assertSame('won', $second->lead->stage);
        $this->assertNull($second->needs_human_reason, 'an API-created affiliate needs no verification');
        $this->assertTrue(Event::where('tenant_id', $this->tenant->id)->where('event_type', 'outreach.affiliate.created')->exists());
        Mail::assertSent(PartnerOutreachMail::class, 4);
    }

    public function test_unsubscribe_and_decline_actions_close_the_thread_cleanly(): void
    {
        // Neither message trips the inbound classifier's STOP/decline rules; the model reads the intent.
        $leaver = $this->invited('leaver', 'leaver@example.test');
        $this->model(['reply' => 'Understood, you will not hear from us again. All the best with the channel.', 'action' => 'unsubscribe']);
        $this->inbound($leaver, 'Honestly I get too many of these and would rather you did not write again.');
        $this->approve($this->approval('outreach_reply', 'pending')->id);

        $leaver->refresh();
        $this->assertSame(PartnerProspect::STATUS_UNSUBSCRIBED, $leaver->status);
        $this->assertNotNull($leaver->unsubscribed_at);
        $this->assertNull($leaver->next_send_at);
        $this->assertTrue(ConsentRecord::hasOptOut((string) $this->tenant->id, 'leaver@example.test', 'email'));

        $decliner = $this->invited('decliner', 'decliner@example.test');
        $this->model(['reply' => 'Completely understood, thanks for letting us know. The door stays open.', 'action' => 'decline_close']);
        $this->inbound($decliner, 'I only do sponsored content with upfront fees these days, so this is not something I would take on.', 2);

        // "upfront" is a deterministic handoff trigger: the bot never answered.
        $this->assertSame(PartnerProspect::STATUS_HANDOFF, $decliner->refresh()->status);

        $polite = $this->invited('polite', 'polite@example.test');
        $this->inbound($polite, 'Appreciate the note but my audience is not marketers, so I will sit this one out.', 3);
        $this->approve($this->approval('outreach_reply', 'pending')->id);

        $polite->refresh();
        $this->assertSame(PartnerProspect::STATUS_DECLINED, $polite->status);
        $this->assertNotNull($polite->declined_at);
        $this->assertSame('lost', $polite->lead->stage);
        Mail::assertSent(PartnerOutreachMail::class, 5); // three invites + two closing replies
    }
}
