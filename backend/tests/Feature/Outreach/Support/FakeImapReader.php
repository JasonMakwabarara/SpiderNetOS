<?php

declare(strict_types=1);

namespace Tests\Feature\Outreach\Support;

use App\Services\Outreach\Inbound\ImapMailboxReader;
use App\Services\Outreach\Inbound\InboundMessage;

/** In-memory mailbox for the poller tests: yields fixtures, records calls. */
class FakeImapReader implements ImapMailboxReader
{
    /** @var list<InboundMessage> */
    public array $messages = [];

    /** @var list<array{folder: string, after_uid: ?int, limit: int}> */
    public array $fetches = [];

    public bool $readable = true;

    public function canRead(array $credentials): bool
    {
        return $this->readable && ! empty($credentials['imap_password']);
    }

    public function checkLogin(array $credentials, string $folder = 'INBOX'): array
    {
        return $this->readable ? ['ok' => true, 'detail' => ['folder' => $folder]] : ['ok' => false, 'error' => 'fake: unreadable'];
    }

    public function fetchNew(array $credentials, string $folder = 'INBOX', ?int $afterUid = null, int $limit = 200): iterable
    {
        $this->fetches[] = ['folder' => $folder, 'after_uid' => $afterUid, 'limit' => $limit];

        $list = array_values(array_filter($this->messages, fn (InboundMessage $m) => $afterUid === null || $m->uid > $afterUid));
        usort($list, fn (InboundMessage $a, InboundMessage $b) => $a->uid <=> $b->uid);

        return array_slice($list, 0, $limit);
    }

    /** Build a fixture quickly. @param array<string, mixed> $overrides */
    public static function message(array $overrides = []): InboundMessage
    {
        $defaults = [
            'uid' => 1,
            'messageId' => 'msg-'.bin2hex(random_bytes(6)).'@example.test',
            'inReplyTo' => [],
            'references' => [],
            'from' => 'creator@example.test',
            'fromName' => 'Creator',
            'recipients' => ['partners@hannah-ai.test'],
            'subject' => 'Re: Partnering with Hannah AI',
            'textBody' => 'Sounds interesting, how does payout work?',
            'headers' => [],
            'date' => new \DateTimeImmutable,
            'rawBody' => '',
        ];
        $a = array_replace($defaults, $overrides);

        return new InboundMessage(
            $a['uid'], $a['messageId'], $a['inReplyTo'], $a['references'], $a['from'], $a['fromName'],
            $a['recipients'], $a['subject'], $a['textBody'], $a['headers'], $a['date'], $a['rawBody'],
        );
    }
}
