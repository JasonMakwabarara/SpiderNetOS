<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Mail\BrandedMail;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Email adapter for MessageDispatchService.
 *
 * Two paths: the legacy branded platform mail (sales nurture, unchanged), and
 * a prebuilt Mailable passed in $options['mailable'] (partner outreach) that
 * is sent through the tenant's own mailbox via TenantMailerFactory so the
 * From/Reply-To identity is the tenant's, not SpiderNet's.
 */
class EmailChannel implements ChannelContract
{
    public function __construct(private readonly TenantMailerFactory $mailers) {}

    public function send(Lead $lead, string $body, array $options = []): array
    {
        if (empty($lead->email)) {
            return ['success' => false, 'error' => 'Lead has no email.'];
        }

        $mailable = $options['mailable'] ?? null;

        try {
            if ($mailable instanceof Mailable) {
                return $this->sendAsTenant($lead, $mailable, $options);
            }

            $tenant = Tenant::find($lead->tenant_id);
            $subject = (string) ($options['subject'] ?? 'A message from your team');
            $messageId = (string) Str::uuid();

            Mail::to($lead->email)->send(new BrandedMail($tenant, $subject, $body));

            return ['success' => true, 'provider_message_id' => $messageId];
        } catch (\Throwable $e) {
            Log::error('EmailChannel send failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $options */
    private function sendAsTenant(Lead $lead, Mailable $mailable, array $options): array
    {
        $mailer = $this->mailers->for((string) $lead->tenant_id);

        if ($mailer === null) {
            if ($options['require_tenant_mailer'] ?? true) {
                return ['success' => false, 'error' => 'No partner mailbox connected for this tenant (connect zoho_mail first).'];
            }
            $mailer = Mail::mailer();
        }

        $mailer->to($lead->email)->send($mailable);

        // The caller minted the Message-ID (it is on the Mailable's headers), so
        // reply matching can find this row later; fall back to a UUID otherwise.
        return ['success' => true, 'provider_message_id' => (string) ($options['message_id'] ?? Str::uuid())];
    }
}
