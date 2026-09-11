<?php

declare(strict_types=1);

namespace App\Services\Outreach\Inbound;

/**
 * Reads the machine-readable part of a delivery status notification
 * (RFC 3464) and the returned original message, so a bounce can be tied back
 * to the address and the outreach email that caused it.
 */
class DsnParser
{
    /**
     * @return array{is_dsn: bool, hard: bool, recipient: ?string, status: ?string, action: ?string, diagnostic: ?string, original_message_id: ?string}
     */
    public function parse(InboundMessage $message): array
    {
        $raw = $message->rawBody !== '' ? $message->rawBody : $message->textBody;
        $contentType = strtolower((string) $message->header('content-type'));
        $from = strtolower($message->from);

        $isReport = str_contains($contentType, 'multipart/report') || str_contains($contentType, 'delivery-status');
        $isDaemon = str_starts_with($from, 'mailer-daemon@') || str_starts_with($from, 'postmaster@') || str_contains($from, 'mail-delivery');
        $hasFailed = $message->header('x-failed-recipients') !== null;
        $subject = strtolower($message->subject);
        $subjectLooksLikeBounce = (bool) preg_match('/undeliver|delivery (status|failure|has failed)|returned mail|mail delivery failed|failure notice|not delivered/', $subject);

        $recipient = $this->match('/Final-Recipient:\s*rfc822;\s*<?([^\s>]+)>?/i', $raw)
            ?? $this->match('/Original-Recipient:\s*rfc822;\s*<?([^\s>]+)>?/i', $raw)
            ?? ($hasFailed ? trim((string) $message->header('x-failed-recipients')) : null);
        $status = $this->match('/^Status:\s*(\d\.\d{1,3}\.\d{1,3})/mi', $raw);
        $action = $this->match('/^Action:\s*([a-z]+)/mi', $raw);
        $diagnostic = $this->match('/^Diagnostic-Code:\s*(.+)$/mi', $raw);
        $originalId = InboundMessage::cleanId($this->match('/^Message-ID:\s*(<[^>]+>)/mi', $this->returnedPart($raw)));

        $isDsn = $isReport || ($isDaemon && ($recipient !== null || $status !== null || $subjectLooksLikeBounce)) || ($hasFailed && $subjectLooksLikeBounce);

        $hard = $status !== null
            ? str_starts_with($status, '5.')
            : ($action !== null ? $action === 'failed' : (bool) preg_match('/user unknown|no such user|does not exist|mailbox unavailable|rejected|550/i', $raw));

        return [
            'is_dsn' => $isDsn,
            'hard' => $isDsn && $hard,
            'recipient' => $recipient !== null ? strtolower($recipient) : null,
            'status' => $status,
            'action' => $action !== null ? strtolower($action) : null,
            'diagnostic' => $diagnostic !== null ? mb_substr(trim($diagnostic), 0, 250) : null,
            'original_message_id' => $originalId,
        ];
    }

    /** The returned original message usually follows the per-recipient status block. */
    private function returnedPart(string $raw): string
    {
        $pos = stripos($raw, 'message/rfc822');
        if ($pos !== false) {
            return substr($raw, $pos);
        }
        $pos = strpos($raw, 'Final-Recipient:');

        return $pos !== false ? substr($raw, $pos) : $raw;
    }

    private function match(string $pattern, string $haystack): ?string
    {
        return preg_match($pattern, $haystack, $m) === 1 ? trim($m[1]) : null;
    }
}
