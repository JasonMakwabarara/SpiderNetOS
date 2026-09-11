<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Services\Messaging\TenantMailerFactory;
use App\Services\Outreach\Inbound\ImapMailboxReader;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;

/**
 * A tenant-owned mailbox (Zoho Mail by default, any SMTP/IMAP works) used as
 * the identity for partner outreach: mail goes out from it and replies are
 * read back from it.
 *
 * test() proves the SMTP credentials by sending a short message to the
 * mailbox itself. IMAP is verified by the inbox poller once it exists.
 */
class ZohoMailConnector implements ConnectorContract
{
    private readonly TenantMailerFactory $mailers;

    /** Stored credentials layered over the integration's non-secret config. */
    private readonly array $credentials;

    public function __construct(
        private readonly string $tenantId,
        array $credentials,
        array $config = [],
        ?TenantMailerFactory $mailers = null,
    ) {
        $this->credentials = $credentials + $config;
        $this->mailers = $mailers ?? app(TenantMailerFactory::class);
    }

    public function test(): array
    {
        if (! $this->mailers->hasSmtp($this->credentials)) {
            return ['ok' => false, 'error' => 'smtp_host, smtp_username and smtp_password are required.'];
        }

        $sender = $this->mailers->senderFor($this->credentials);
        if (filter_var($sender['address'], FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'error' => 'from_address must be a valid email address.'];
        }

        try {
            $this->mailers->fromCredentials($this->credentials)->raw(
                'This is a connection test from SpiderNet OS. Your partner mailbox is configured for outbound mail.',
                function (Message $message) use ($sender) {
                    $message->from($sender['address'], $sender['name'])
                        ->to($sender['address'])
                        ->subject('SpiderNet OS mailbox connection test');
                },
            );
        } catch (\Throwable $e) {
            Log::warning('zoho_mail connection test failed', ['tenant_id' => $this->tenantId, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'SMTP test failed: '.$e->getMessage()];
        }

        $detail = ['smtp' => 'Authenticated; a test email was sent to '.$sender['address'].'.'];

        if (empty($this->credentials['imap_password'])) {
            $detail['imap'] = 'No IMAP password stored: replies cannot be read until it is added.';

            return ['ok' => true, 'detail' => $detail];
        }

        $imap = app(ImapMailboxReader::class)->checkLogin($this->credentials);
        if (! $imap['ok']) {
            return ['ok' => false, 'error' => (string) ($imap['error'] ?? 'IMAP login failed.'), 'detail' => $detail];
        }
        $detail['imap'] = 'Login OK; the inbox poller can read replies.';

        return ['ok' => true, 'detail' => $detail];
    }

    public function execute(string $action, array $params = []): array
    {
        return match ($action) {
            'send_email' => $this->sendEmail($params),
            default => ['success' => false, 'error' => "Unsupported mailbox action: {$action}"],
        };
    }

    private function sendEmail(array $params): array
    {
        $to = trim((string) ($params['to'] ?? ''));
        $subject = trim((string) ($params['subject'] ?? ''));
        $body = (string) ($params['body'] ?? '');

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || $subject === '' || trim($body) === '') {
            return ['success' => false, 'error' => 'to (valid email), subject and body are required.'];
        }

        $sender = $this->mailers->senderFor($this->credentials);

        try {
            $this->mailers->fromCredentials($this->credentials)->raw($body, function (Message $message) use ($sender, $to, $subject) {
                $message->from($sender['address'], $sender['name'])->to($to)->subject($subject);
            });
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return ['success' => true, 'data' => ['to' => $to, 'from' => $sender['address']]];
    }
}
