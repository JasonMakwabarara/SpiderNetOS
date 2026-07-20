<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Lead;
use App\Models\MessagingNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SMS send via the Twilio Messages API — same shape as WhatsAppChannel without
 * the whatsapp: URI prefix, using the lead's phone number and the tenant's
 * provisioned SMS number.
 */
class SmsChannel implements ChannelContract
{
    public function send(Lead $lead, string $body, array $options = []): array
    {
        if (empty($lead->phone)) {
            return ['success' => false, 'error' => 'Lead has no phone number.'];
        }

        $config = config('telephony.providers.twilio', []);
        if (empty($config['sid']) || empty($config['auth_token'])) {
            return ['success' => false, 'error' => 'Twilio credentials are not configured.'];
        }

        $fromNumber = MessagingNumber::where('tenant_id', $lead->tenant_id)
            ->where('channel', 'sms')
            ->where('is_active', true)
            ->value('phone_number');

        if (! $fromNumber) {
            return ['success' => false, 'error' => 'No active SMS number provisioned for this tenant.'];
        }

        try {
            $response = Http::withBasicAuth($config['sid'], $config['auth_token'])
                ->asForm()
                ->post($config['api_base'].'/Accounts/'.$config['sid'].'/Messages.json', [
                    'To' => $lead->phone,
                    'From' => $fromNumber,
                    'Body' => $body,
                ]);

            if ($response->successful()) {
                return ['success' => true, 'provider_message_id' => $response->json()['sid'] ?? null];
            }

            return ['success' => false, 'error' => 'Twilio returned '.$response->status().': '.$response->body()];
        } catch (\Throwable $e) {
            Log::error('SmsChannel send failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
