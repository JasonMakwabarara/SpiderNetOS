<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Mail\BrandedMail;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmailChannel implements ChannelContract
{
    public function send(Lead $lead, string $body, array $options = []): array
    {
        $subject = (string) ($options['subject'] ?? 'A message from your team');

        if (empty($lead->email)) {
            return ['success' => false, 'error' => 'Lead has no email.'];
        }

        try {
            $tenant = Tenant::find($lead->tenant_id);
            $messageId = (string) Str::uuid();

            Mail::to($lead->email)->send(new BrandedMail($tenant, $subject, $body));

            return ['success' => true, 'provider_message_id' => $messageId];
        } catch (\Throwable $e) {
            Log::error('EmailChannel send failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
