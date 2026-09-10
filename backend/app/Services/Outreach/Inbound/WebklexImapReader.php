<?php

declare(strict_types=1);

namespace App\Services\Outreach\Inbound;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * IMAP reader on webklex/php-imap (pure PHP, no ext-imap). Credentials are
 * the zoho_mail connector's: imap_host/port/username/password, falling back
 * to the SMTP login for the username.
 */
class WebklexImapReader implements ImapMailboxReader
{
    public function canRead(array $credentials): bool
    {
        return ! empty($credentials['imap_password']) && $this->username($credentials) !== '';
    }

    public function checkLogin(array $credentials, string $folder = 'INBOX'): array
    {
        if (! $this->canRead($credentials)) {
            return ['ok' => false, 'error' => 'IMAP password (and username) required.'];
        }

        try {
            $client = $this->client($credentials);
            $client->connect();
            try {
                $client->getFolderByPath($folder);
            } finally {
                $client->disconnect();
            }

            return ['ok' => true, 'detail' => ['folder' => $folder]];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'IMAP login failed: '.$e->getMessage()];
        }
    }

    public function fetchNew(array $credentials, string $folder = 'INBOX', ?int $afterUid = null, int $limit = 200): iterable
    {
        $client = $this->client($credentials);
        $client->connect();

        try {
            $mailbox = $client->getFolderByPath($folder);
            $query = $mailbox->messages()->leaveUnread()->setFetchBody(true);

            $messages = $afterUid !== null && $afterUid > 0
                ? $query->getByUidGreater($afterUid)
                : $query->unseen()->since(now()->subDays(3))->get();

            $list = [];
            foreach ($messages as $message) {
                $list[] = $message;
            }
            usort($list, fn (Message $a, Message $b) => $a->getUid() <=> $b->getUid());

            foreach (array_slice($list, 0, $limit) as $message) {
                yield $this->toInbound($message);
            }
        } finally {
            $client->disconnect();
        }
    }

    private function client(array $credentials): Client
    {
        return (new ClientManager)->make([
            'host' => (string) (($credentials['imap_host'] ?? '') ?: 'imap.zoho.com'),
            'port' => (int) (($credentials['imap_port'] ?? 0) ?: 993),
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => $this->username($credentials),
            'password' => (string) ($credentials['imap_password'] ?? ''),
            'protocol' => 'imap',
        ]);
    }

    private function username(array $credentials): string
    {
        return trim((string) (($credentials['imap_username'] ?? '') ?: ($credentials['smtp_username'] ?? '') ?: ($credentials['from_address'] ?? '')));
    }

    private function toInbound(Message $message): InboundMessage
    {
        $headers = [];
        foreach ($message->getHeader()?->getAttributes() ?? [] as $name => $attribute) {
            $headers[strtolower((string) $name)] = trim($attribute->toString());
        }

        $recipients = [];
        foreach (['to', 'cc', 'delivered-to', 'x-original-to', 'envelope-to', 'x-envelope-to'] as $name) {
            if (isset($headers[$name]) && preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $headers[$name], $m)) {
                foreach ($m[0] as $address) {
                    $recipients[] = strtolower($address);
                }
            }
        }

        $from = '';
        $fromName = null;
        $first = $message->getFrom()->first();
        if (is_object($first)) {
            $from = strtolower((string) ($first->mail ?? ''));
            $fromName = trim((string) ($first->personal ?? '')) ?: null;
        }

        $text = trim($message->getTextBody());
        if ($text === '') {
            $text = trim(html_entity_decode(strip_tags(preg_replace('/<(br|p|div|tr)[^>]*>/i', "\n", $message->getHTMLBody()) ?? '')));
        }

        $date = $message->getDate()->first();

        return new InboundMessage(
            uid: (int) $message->getUid(),
            messageId: InboundMessage::cleanId($message->getMessageId()->toString()),
            inReplyTo: InboundMessage::splitIds($message->getInReplyTo()->toString()),
            references: InboundMessage::splitIds($message->getReferences()->toString()),
            from: $from,
            fromName: $fromName,
            recipients: array_values(array_unique($recipients)),
            subject: trim($message->getSubject()->toString()),
            textBody: $text,
            headers: $headers,
            date: $date instanceof \DateTimeInterface ? $date : null,
            rawBody: (string) $message->getRawBody(),
        );
    }
}
