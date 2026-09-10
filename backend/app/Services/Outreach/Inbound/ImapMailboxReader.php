<?php

declare(strict_types=1);

namespace App\Services\Outreach\Inbound;

/**
 * Reads a tenant's partner mailbox. The only seam that touches an IMAP
 * library; tests bind a fake that yields InboundMessage fixtures.
 */
interface ImapMailboxReader
{
    /** Whether the stored mailbox credentials include what IMAP needs. */
    public function canRead(array $credentials): bool;

    /**
     * Log in and select the folder without fetching anything.
     *
     * @return array{ok: bool, error?: string, detail?: array<string, mixed>}
     */
    public function checkLogin(array $credentials, string $folder = 'INBOX'): array;

    /**
     * Messages newer than $afterUid (or recent unseen ones when null), oldest
     * first, never flagging them as read.
     *
     * @return iterable<int, InboundMessage>
     */
    public function fetchNew(array $credentials, string $folder = 'INBOX', ?int $afterUid = null, int $limit = 200): iterable;
}
