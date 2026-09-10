<?php

declare(strict_types=1);

namespace Tests\Unit\Outreach;

use App\Services\Outreach\Inbound\DsnParser;
use App\Services\Outreach\Inbound\InboundEmailClassifier;
use App\Services\Outreach\Inbound\InboundMessage;
use PHPUnit\Framework\TestCase;

class InboundEmailClassifierTest extends TestCase
{
    private InboundEmailClassifier $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = new InboundEmailClassifier(new DsnParser);
    }

    private function msg(string $body, array $headers = [], string $subject = 'Re: Partnering', string $from = 'creator@example.test', string $raw = ''): InboundMessage
    {
        return new InboundMessage(1, 'id@example.test', [], [], $from, null, ['partners@hannah-ai.test'], $subject, $body, $headers, null, $raw);
    }

    public function test_plain_reply_is_a_reply_with_quotes_and_signature_stripped(): void
    {
        $body = "Sounds interesting, how does payout work?\n\nOn Tue, Sep 9, 2026 at 10:00 Hannah AI Partnerships <partners@hannah-ai.test> wrote:\n> Hi Mike,\n> I run partnerships...\n-- \nMike\nSent from my phone";
        $r = $this->c->classify($this->msg($body));

        $this->assertSame(InboundEmailClassifier::REPLY, $r['kind']);
        $this->assertSame('Sounds interesting, how does payout work?', $r['text']);
    }

    public function test_outlook_style_history_is_stripped(): void
    {
        $body = "Yes please send details\r\n\r\n-----Original Message-----\r\nFrom: partners@hannah-ai.test\r\nSent: Tuesday\r\nSubject: Partnering";
        $this->assertSame('Yes please send details', $this->c->classify($this->msg($body))['text']);
    }

    public function test_auto_responders_by_header_and_subject(): void
    {
        $this->assertSame(InboundEmailClassifier::AUTO_REPLY, $this->c->classify($this->msg('I am away', ['auto-submitted' => 'auto-replied']))['kind']);
        $this->assertSame(InboundEmailClassifier::AUTO_REPLY, $this->c->classify($this->msg('I am away', ['x-autoreply' => 'yes']))['kind']);
        $this->assertSame(InboundEmailClassifier::AUTO_REPLY, $this->c->classify($this->msg('Thanks for your email', ['precedence' => 'bulk']))['kind']);
        $this->assertSame(InboundEmailClassifier::AUTO_REPLY, $this->c->classify($this->msg('Back on Monday', [], 'Automatic reply: Partnering with Hannah AI'))['kind']);
        $this->assertSame(InboundEmailClassifier::AUTO_REPLY, $this->c->classify($this->msg('Back on Monday', [], 'Out of Office'))['kind']);
        // Auto-Submitted: no is NOT an auto-responder.
        $this->assertSame(InboundEmailClassifier::REPLY, $this->c->classify($this->msg('Hello', ['auto-submitted' => 'no']))['kind']);
    }

    public function test_stop_words_and_declines(): void
    {
        $this->assertSame(InboundEmailClassifier::OPT_OUT, $this->c->classify($this->msg('STOP'))['kind']);
        $this->assertSame(InboundEmailClassifier::OPT_OUT, $this->c->classify($this->msg('Please unsubscribe me from this list.'))['kind']);
        $this->assertSame(InboundEmailClassifier::OPT_OUT, $this->c->classify($this->msg('Do not contact me again'))['kind']);
        $this->assertSame(InboundEmailClassifier::OPT_OUT, $this->c->classify($this->msg('ok', [], 'Unsubscribe'))['kind']);
        $this->assertSame(InboundEmailClassifier::DECLINE, $this->c->classify($this->msg("Thanks but I'm not interested right now."))['kind']);
        $this->assertSame(InboundEmailClassifier::DECLINE, $this->c->classify($this->msg('No thanks.'))['kind']);
        // Stop words deep inside quoted history do not count.
        $body = "Tell me more\n\nOn Mon someone wrote:\n> unsubscribe link below";
        $this->assertSame(InboundEmailClassifier::REPLY, $this->c->classify($this->msg($body))['kind']);
    }

    public function test_bounce_is_detected_from_report_content_type_or_mailer_daemon(): void
    {
        $raw = "Reporting-MTA: dns; mx.zoho.com\n\nFinal-Recipient: rfc822; creator@example.test\nAction: failed\nStatus: 5.1.1\nDiagnostic-Code: smtp; 550 5.1.1 user unknown\n\nContent-Type: message/rfc822\n\nMessage-ID: <abc.tok@hannah-ai.test>\nSubject: Partnering";
        $r = $this->c->classify($this->msg('Delivery failed', ['content-type' => 'multipart/report; report-type=delivery-status'], 'Undelivered Mail Returned to Sender', 'mailer-daemon@mx.zoho.com', $raw));

        $this->assertSame(InboundEmailClassifier::BOUNCE, $r['kind']);
        $this->assertTrue($r['dsn']['hard']);
        $this->assertSame('creator@example.test', $r['dsn']['recipient']);
        $this->assertSame('5.1.1', $r['dsn']['status']);
        $this->assertSame('abc.tok@hannah-ai.test', $r['dsn']['original_message_id']);
        $this->assertStringContainsString('user unknown', $r['dsn']['diagnostic']);

        $soft = $this->c->classify($this->msg('Delayed', ['content-type' => 'multipart/report; report-type=delivery-status'], 'Delivery Status Notification (Delay)', 'postmaster@example.net', "Final-Recipient: rfc822; x@y.test\nAction: delayed\nStatus: 4.4.1"));
        $this->assertSame(InboundEmailClassifier::BOUNCE, $soft['kind']);
        $this->assertFalse($soft['dsn']['hard']);

        // A human named "postmaster" writing a normal mail is not a bounce.
        $this->assertSame(InboundEmailClassifier::REPLY, $this->c->classify($this->msg('Hi, happy to chat', [], 'Re: Partnering', 'postmaster@creator.test'))['kind']);
    }
}
