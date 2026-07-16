<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Lead;
use App\Models\MessagingNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp send via the Twilio Messages API — same credential shape and HTTP
 * pattern as TelephonyService::initiateTwilioCall(), just a different
 * endpoint (Messages.json instead of Calls.json) and whatsapp: URI prefix.
 */
class WhatsAppChannel
{
    public function send(Lead $lead, string $body): array
    {
        if (empty($lead->whatsapp_number)) {
            return ['success' => false, 'error' => 'Lead has no whatsapp_number.'];
        }

        $config = config('telephony.providers.twilio', []);
        if (empty($config['sid']) || empty($config['auth_token'])) {
            return ['success' => false, 'error' => 'Twilio credentials are not configured.'];
        }

        $fromNumber = MessagingNumber::where('tenant_id', $lead->tenant_id)
            ->where('channel', 'whatsapp')
            ->where('is_active', true)
            ->value('phone_number');

        if (! $fromNumber) {
            return ['success' => false, 'error' => 'No active WhatsApp number provisioned for this tenant.'];
        }

        try {
            $response = Http::withBasicAuth($config['sid'], $config['auth_token'])
                ->asForm()
                ->post($config['api_base'].'/Accounts/'.$config['sid'].'/Messages.json', [
                    'To' => 'whatsapp:'.$lead->whatsapp_number,
                    'From' => 'whatsapp:'.$fromNumber,
                    'Body' => $body,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return ['success' => true, 'provider_message_id' => $data['sid'] ?? null];
            }

            return ['success' => false, 'error' => 'Twilio returned '.$response->status().': '.$response->body()];
        } catch (\Throwable $e) {
            Log::error('WhatsAppChannel send failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
