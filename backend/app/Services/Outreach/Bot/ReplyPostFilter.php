<?php

declare(strict_types=1);

namespace App\Services\Outreach\Bot;

/**
 * Parses the model's JSON and refuses anything the FACTS do not cover:
 * figures that are not in the facts, links off the allowlist, banned
 * promises. A refusal becomes a handoff, never a sent message.
 */
class ReplyPostFilter
{
    public const MAX_CHARS = 1200;

    private const BANNED = '/\b(guarantee[ds]?|earn up to|make \$?\d|get rich|risk[- ]free|exclusive rate|special rate|custom commission|higher commission|discount code|coupon)\b/i';

    /**
     * @param  array<string, string|int>  $facts
     * @return array{ok: bool, reason: ?string, reply: string, action: string, extracted: array<string, ?string>, confidence: float}
     */
    public function check(string $raw, array $facts): array
    {
        $parsed = $this->parse($raw);
        if ($parsed === null) {
            return $this->fail('invalid_json', $raw);
        }

        $reply = trim((string) ($parsed['reply'] ?? ''));
        $action = strtolower(trim((string) ($parsed['action'] ?? 'none'))) ?: 'none';
        $extracted = (array) ($parsed['extracted'] ?? []);
        $confidence = (float) ($parsed['confidence'] ?? 0.5);

        $result = [
            'ok' => true, 'reason' => null, 'reply' => $reply, 'action' => $action,
            'extracted' => [
                'email' => $this->str($extracted['email'] ?? null), 'country' => $this->str($extracted['country'] ?? null), 'handle' => $this->str($extracted['handle'] ?? null),
            ],
            'confidence' => max(0.0, min(1.0, $confidence)),
        ];

        if (! in_array($action, RecruiterPromptBuilder::ACTIONS, true)) {
            return $this->fail('unknown_action', $raw, $result);
        }
        if ($action === 'handoff') {
            return $result; // the model itself asked for a human
        }
        if ($reply === '' || mb_strlen($reply) > self::MAX_CHARS) {
            return $this->fail($reply === '' ? 'empty_reply' : 'too_long', $raw, $result);
        }
        if (preg_match(self::BANNED, $reply) === 1) {
            return $this->fail('banned_phrase', $raw, $result);
        }
        if (! $this->linksAllowed($reply, $facts)) {
            return $this->fail('link_not_allowed', $raw, $result);
        }
        if (! $this->figuresAllowed($reply, $facts)) {
            return $this->fail('unverified_figure', $raw, $result);
        }

        return $result;
    }

    /** @return array<string, mixed>|null the first JSON object in the text */
    public function parse(string $raw): ?array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw) ?? $raw;
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function linksAllowed(string $reply, array $facts): bool
    {
        if (preg_match_all('~https?://[^\s<>"\']+~i', $reply, $m) === 0) {
            return true;
        }
        $allowedHosts = [];
        foreach (['terms_url', 'join_url'] as $key) {
            $host = parse_url((string) ($facts[$key] ?? ''), PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $allowedHosts[] = strtolower(preg_replace('/^www\./', '', $host) ?? $host);
            }
        }
        foreach ($m[0] as $url) {
            $host = strtolower((string) parse_url(rtrim($url, '.,)'), PHP_URL_HOST));
            $host = preg_replace('/^www\./', '', $host) ?? $host;
            if ($host === '' || ! in_array($host, $allowedHosts, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every percentage, dollar amount and day/month/year count must be the
     * matching FACTS figure: a percentage may only be the commission, an
     * amount only the minimum payout, a span only the months / cookie / hold.
     */
    private function figuresAllowed(string $reply, array $facts): bool
    {
        $groups = [
            ['/(\d+(?:[.,]\d+)?)\s*%/', [$facts['commission_pct'] ?? null]],
            ['/(?:\$|usd\s*)(\d+(?:[.,]\d+)?)|(\d+(?:[.,]\d+)?)\s*(?:usd|dollars)/i', [$facts['min_payout_usd'] ?? null]],
            ['/(\d+)\s*[- ]?(?:days?|months?|years?|weeks?)\b/i', [$facts['months'] ?? null, $facts['cookie_days'] ?? null, $facts['hold_days'] ?? null]],
        ];

        foreach ($groups as [$pattern, $allowedRaw]) {
            $allowed = array_map(
                fn ($v) => $this->normalizeNumber((string) $v),
                array_filter($allowedRaw, fn ($v) => $v !== null && $v !== ''),
            );
            preg_match_all($pattern, $reply, $m);
            $figures = array_filter(array_merge($m[1], $m[2] ?? []), fn ($f) => $f !== '');
            foreach ($figures as $figure) {
                if (! in_array($this->normalizeNumber($figure), $allowed, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function normalizeNumber(string $number): string
    {
        $number = str_replace(',', '.', $number);

        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    private function fail(string $reason, string $raw, ?array $partial = null): array
    {
        $partial ??= ['reply' => '', 'action' => 'none', 'extracted' => ['email' => null, 'country' => null, 'handle' => null], 'confidence' => 0.0];

        return ['ok' => false, 'reason' => $reason, 'raw' => mb_substr($raw, 0, 2000)] + $partial;
    }

    private function str(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || strtolower($value) === 'null' ? null : $value;
    }
}
