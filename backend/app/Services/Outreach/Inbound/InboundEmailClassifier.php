<?php

declare(strict_types=1);

namespace App\Services\Outreach\Inbound;

/**
 * Decides what an inbound email IS before anyone acts on it, in a fixed
 * order: bounce, auto-responder, opt-out, decline, then an ordinary reply.
 * Also strips quoted history so downstream readers (humans, later the bot)
 * see only what the creator actually wrote.
 */
class InboundEmailClassifier
{
    public const BOUNCE = 'bounce';

    public const AUTO_REPLY = 'auto_reply';

    public const OPT_OUT = 'opt_out';

    public const DECLINE = 'decline';

    public const REPLY = 'reply';

    private const STOP_PATTERN = '/\b(unsubscribe|opt[\s-]?out|remove me|take me off|stop (emailing|contacting|messaging) me|do not (email|contact|message) me( again)?|no more emails|leave me alone)\b|^\s*stop\s*[.!]?\s*$/im';

    private const DECLINE_PATTERN = '/\b(not interested|no thanks|no thank you|pass on this|not for me|not a fit|not the right fit|don\'t think this is for me|no longer interested|please don\'t follow up)\b/i';

    public function __construct(private readonly DsnParser $dsn) {}

    /**
     * @return array{kind: string, text: string, dsn: ?array<string, mixed>}
     */
    public function classify(InboundMessage $message): array
    {
        $dsn = $this->dsn->parse($message);
        if ($dsn['is_dsn']) {
            return ['kind' => self::BOUNCE, 'text' => $this->clean($message->textBody), 'dsn' => $dsn];
        }

        if ($this->isAutoResponder($message)) {
            return ['kind' => self::AUTO_REPLY, 'text' => $this->clean($message->textBody), 'dsn' => null];
        }

        $text = $this->clean($message->textBody);
        $head = mb_substr($text, 0, 600);

        if (preg_match(self::STOP_PATTERN, $head) === 1 || preg_match('/unsubscribe/i', $message->subject) === 1) {
            return ['kind' => self::OPT_OUT, 'text' => $text, 'dsn' => null];
        }

        if (preg_match(self::DECLINE_PATTERN, $head) === 1) {
            return ['kind' => self::DECLINE, 'text' => $text, 'dsn' => null];
        }

        return ['kind' => self::REPLY, 'text' => $text, 'dsn' => null];
    }

    public function isAutoResponder(InboundMessage $message): bool
    {
        $auto = strtolower((string) $message->header('auto-submitted'));
        if ($auto !== '' && $auto !== 'no') {
            return true;
        }
        foreach (['x-autoreply', 'x-autorespond', 'x-auto-response-suppress'] as $h) {
            if ($message->header($h) !== null) {
                return true;
            }
        }
        $precedence = strtolower((string) $message->header('precedence'));
        if (in_array($precedence, ['bulk', 'auto_reply', 'junk', 'list'], true)) {
            return true;
        }
        if ($message->header('list-id') !== null || $message->header('list-unsubscribe') !== null) {
            return true;
        }

        return (bool) preg_match('/^(automatic reply|auto(matic)?[\s-]?reply|autoreply|out of (the )?office|ooo:|abwesenheit|r[ée]ponse automatique|respuesta autom[áa]tica)/i', trim($message->subject));
    }

    /** Drop quoted history, signatures and trailing whitespace. */
    public function clean(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);

        // Cut at the first reply separator.
        $separators = [
            '/^On .{5,200}wrote:\s*$/m',
            '/^-{2,}\s*Original Message\s*-{2,}/mi',
            '/^_{5,}\s*$/m',
            '/^From:\s.+\nSent:\s.+/mi',
            '/^Le .{5,200} a écrit\s*:/m',
            '/^Am .{5,200} schrieb .+:/m',
            '/^>+\s?/m',
        ];
        foreach ($separators as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) === 1) {
                $text = substr($text, 0, $m[0][1]);
            }
        }

        // Signature delimiter.
        if (preg_match('/^-- \s*$/m', $text, $m, PREG_OFFSET_CAPTURE) === 1) {
            $text = substr($text, 0, $m[0][1]);
        }

        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
