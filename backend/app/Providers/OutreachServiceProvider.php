<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Outreach\Inbound\ImapMailboxReader;
use App\Services\Outreach\Inbound\WebklexImapReader;
use Illuminate\Support\ServiceProvider;

/** Partner-outreach bindings: the IMAP reader seam. */
class OutreachServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ImapMailboxReader::class, WebklexImapReader::class);
    }
}
