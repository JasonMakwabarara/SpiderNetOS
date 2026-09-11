<?php

declare(strict_types=1);

namespace App\Services\Outreach\Inbound;

/**
 * One email pulled from a tenant's partner mailbox, already decoded. Readers
 * (IMAP, fakes in tests) produce these; the classifier and ingestor only ever
 * see this shape, never the IMAP library.
 */
final class InboundMessage
{
    /**
     * @param  list<string>  $inReplyTo  Message-IDs without angle brackets
     * @param  list<string>  $references  Message-IDs without angle brackets
     * @param  list<string>  $recipients  every To/Cc/Delivered-To/X-Original-To address, lower-cased
     * @param  array<string, string>  $headers  lower-cased header name => first value
     */
    public function __construct(
        public readonly int $uid,
        public readonly ?string $messageId,
        public readonly array $inReplyTo,
        public readonly array $references,
        public readonly string $from,
        public readonly ?string $fromName,
        public readonly array $recipients,
        public readonly string $subject,
        public readonly string $textBody,
        public readonly array $headers,
        public readonly ?\DateTimeInterface $date = null,
        public readonly string $rawBody = '',
    ) {}

    /** Case-insensitive header lookup. */
    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;

        return $value === null ? null : trim((string) $value);
    }

    /** Stable key for dedupe even when the sender omitted a Message-ID. */
    public function dedupeKey(): string
    {
        if ($this->messageId !== null && $this->messageId !== '') {
            return $this->messageId;
        }

        return 'synth-'.sha1(strtolower($this->from).'|'.($this->date?->format('c') ?? '').'|'.$this->subject.'|'.mb_substr($this->textBody, 0, 200));
    }

    /** Strip angle brackets and whitespace from a Message-ID-like string. */
    public static function cleanId(?string $id): ?string
    {
        $id = trim((string) $id, " \t\r\n<>");

        return $id === '' ? null : $id;
    }

    /** Split a References / In-Reply-To header into clean ids. @return list<string> */
    public static function splitIds(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        preg_match_all('/<([^>]+)>/', $value, $m);
        $ids = $m[1] !== [] ? $m[1] : (preg_split('/\s+/', trim($value)) ?: []);

        return array_values(array_unique(array_filter(array_map(fn ($v) => self::cleanId((string) $v), $ids))));
    }
}
