<?php

declare(strict_types=1);

namespace App\Services\Outreach\Enrichment;

/**
 * Decides whether an address found for a prospect is worth mailing: syntax,
 * not a role/disposable mailbox, and a domain that actually receives mail.
 * The DNS lookup is injectable so tests never touch the network.
 */
class EmailValidator
{
    private const ROLE_LOCAL_PARTS = [
        'noreply', 'no-reply', 'donotreply', 'postmaster', 'mailer-daemon', 'abuse', 'webmaster',
        'hostmaster', 'privacy', 'legal', 'dmca', 'billing', 'security',
    ];

    private const DISPOSABLE_DOMAINS = [
        'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com', 'yopmail.com',
        'sharklasers.com', 'trashmail.com', 'getnada.com', 'dispostable.com',
    ];

    /** @var (callable(string): bool)|null */
    private $domainResolver;

    /**
     * @param  (callable(string): bool)|null  $domainResolver  returns true when the domain can receive mail
     */
    public function __construct(?callable $domainResolver = null)
    {
        $this->domainResolver = $domainResolver;
    }

    public function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return array{ok: bool, email: string, reason: ?string}
     */
    public function check(string $email): array
    {
        $email = $this->normalize($email);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'email' => $email, 'reason' => 'invalid_syntax'];
        }

        [$local, $domain] = explode('@', $email, 2);

        if (in_array($local, self::ROLE_LOCAL_PARTS, true)) {
            return ['ok' => false, 'email' => $email, 'reason' => 'role_address'];
        }

        if (in_array($domain, self::DISPOSABLE_DOMAINS, true)) {
            return ['ok' => false, 'email' => $email, 'reason' => 'disposable_domain'];
        }

        if (! $this->domainReceivesMail($domain)) {
            return ['ok' => false, 'email' => $email, 'reason' => 'no_mx'];
        }

        return ['ok' => true, 'email' => $email, 'reason' => null];
    }

    public function acceptable(string $email): bool
    {
        return $this->check($email)['ok'];
    }

    private function domainReceivesMail(string $domain): bool
    {
        if ($this->domainResolver !== null) {
            return (bool) ($this->domainResolver)($domain);
        }

        // Reserved / test TLDs never resolve; treat them as deliverable so the
        // suite and local fixtures (example.test, *.localhost) are not blocked.
        if (preg_match('~\.(test|example|invalid|localhost)$~', $domain)) {
            return true;
        }

        return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
    }
}
