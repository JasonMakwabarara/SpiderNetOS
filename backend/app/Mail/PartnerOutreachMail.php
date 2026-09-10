<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Symfony\Component\Mime\Email;

/**
 * One partner-outreach email, sent AS the tenant's mailbox (from/reply-to
 * come from the connected zoho_mail credentials, never from SpiderNet).
 *
 * Carries everything reply matching and compliance need: our own Message-ID,
 * threading headers, List-Unsubscribe (mailto + one-click URL) and a footer
 * with the operator's legal name, postal address and the reason for contact.
 */
class PartnerOutreachMail extends Mailable
{
    /**
     * @param  array{address: string, name: ?string}  $sender
     * @param  list<string>  $references  Message-IDs without angle brackets
     * @param  array{legal_name: string, postal_address: ?string, reason: string, unsubscribe_url: string}  $footer
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText,
        public readonly array $sender,
        public readonly string $replyToAddress,
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly array $footer,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->sender['address'], $this->sender['name'] ?? null),
            replyTo: [new Address($this->replyToAddress)],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.outreach.partner',
            text: 'emails.outreach.partner_text',
            with: ['bodyText' => $this->bodyText, 'footer' => $this->footer],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageId,
            references: $this->references,
            text: [
                'List-Unsubscribe' => '<mailto:'.$this->replyToAddress.'?subject=unsubscribe>, <'.$this->footer['unsubscribe_url'].'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        );
    }

    public function build(): static
    {
        // In-Reply-To is an identification header in Symfony Mime, so it
        // cannot go through Headers::$text (that would throw); add it here.
        if ($this->inReplyTo !== null && $this->inReplyTo !== '') {
            $inReplyTo = $this->inReplyTo;
            $this->withSymfonyMessage(function (Email $message) use ($inReplyTo) {
                $message->getHeaders()->addIdHeader('In-Reply-To', $inReplyTo);
            });
        }

        return $this;
    }
}
