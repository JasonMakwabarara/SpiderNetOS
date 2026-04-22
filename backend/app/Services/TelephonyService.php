<?php

namespace App\Services;

use App\Models\VoiceNumber;
use App\Models\VoiceCall;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Telephony Service
 *
 * Handles Twilio/Vonage integration for voice calls.
 * Supports both Gather mode (webhook STT) and Stream mode (WebSocket).
 */
class TelephonyService
{
    private string $provider;
    private array $config;

    public function __construct()
    {
        $this->provider = config('telephony.default');
        $this->config = config("telephony.providers.{$this->provider}", []);
    }

    /**
     * Resolve tenant and agent from incoming call.
     */
    public function resolveTenant(string $toNumber, string $fromNumber): ?array
    {
        $voiceNumber = VoiceNumber::with('tenant')
            ->where('phone_number', $this->normalizePhone($toNumber))
            ->where('is_active', true)
            ->first();

        if (!$voiceNumber) {
            Log::warning('No voice number mapping found', [
                'to' => $toNumber,
                'from' => $fromNumber,
            ]);
            return null;
        }

        return [
            'tenant_id' => $voiceNumber->tenant_id,
            'agent_id' => $voiceNumber->agent_id,
            'voice_number' => $voiceNumber,
            'config' => array_merge(
                $voiceNumber->config ?? [],
                ['from_number' => $fromNumber]
            ),
        ];
    }

    /**
     * Log a new call record.
     */
    public function logCall(
        string $tenantId,
        string $callSid,
        string $toNumber,
        string $fromNumber,
        string $direction = 'inbound',
        string $status = 'initiated',
        ?array $metadata = null
    ): VoiceCall {
        return VoiceCall::create([
            'tenant_id' => $tenantId,
            'call_sid' => $callSid,
            'phone_number' => $this->normalizePhone($toNumber),
            'from_number' => $this->normalizePhone($fromNumber),
            'direction' => $direction,
            'status' => $status,
            'metadata' => $metadata,
            'started_at' => now(),
        ]);
    }

    /**
     * Update call status.
     */
    public function updateCallStatus(string $callSid, string $status, ?int $duration = null): void
    {
        $call = VoiceCall::where('call_sid', $callSid)->first();
        if (!$call) {
            Log::warning('Call not found for status update', ['call_sid' => $callSid]);
            return;
        }

        $call->status = $status;
        if ($duration !== null) {
            $call->duration_seconds = $duration;
        }
        if (in_array($status, ['completed', 'failed', 'busy', 'no-answer'])) {
            $call->ended_at = now();
        }
        $call->save();
    }

    /**
     * Append transcript entry.
     */
    public function appendTranscript(string $callSid, string $speaker, string $text): void
    {
        $call = VoiceCall::where('call_sid', $callSid)->first();
        if (!$call) {
            return;
        }

        $transcript = $call->transcript ?? [];
        $transcript[] = [
            'speaker' => $speaker, // 'caller' or 'agent'
            'text' => $text,
            'timestamp' => now()->toIso8601String(),
        ];
        $call->transcript = $transcript;
        $call->save();
    }

    /**
     * Dispatch voice intent to MetaPlanner via Redis.
     */
    public function dispatchVoiceIntent(
        string $tenantId,
        string $agentId,
        string $callSid,
        string $callerInput,
        array $context = []
    ): void {
        Redis::publish('agent:dispatch', json_encode([
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'intent' => 'voice.interaction',
            'context' => array_merge($context, [
                'call_sid' => $callSid,
                'caller_input' => $callerInput,
                'channel' => 'voice',
                'response_format' => 'voice_chunk',
            ]),
        ]));
    }

    /**
     * Generate TwiML for initial greeting.
     */
    public function generateGreetingTwiML(string $message, ?string $gatherSpeech = null): string
    {
        $response = new \SimpleXMLElement('<Response/>');

        // Add greeting
        $say = $response->addChild('Say');
        $say->addAttribute('voice', config('telephony.tts.voice', 'Polly.Joanna'));
        $say[0] = $message;

        // Add gather if we expect speech input
        if ($gatherSpeech !== null) {
            $gather = $response->addChild('Gather');
            $gather->addAttribute('input', 'speech');
            $gather->addAttribute('timeout', (string) config('telephony.agent.gather_timeout', 5));
            $gather->addAttribute('speechTimeout', config('telephony.agent.gather_speech_timeout', 'auto'));
            $gather->addAttribute('action', '/voice/gather');
            $gather->addAttribute('method', 'POST');

            // Fallback if no speech detected
            $gatherSay = $gather->addChild('Say');
            $gatherSay->addAttribute('voice', config('telephony.tts.voice', 'Polly.Joanna'));
            $gatherSay[0] = $gatherSpeech;
        } else {
            // End call if no gather needed
            $response->addChild('Hangup');
        }

        return $response->asXML();
    }

    /**
     * Generate TwiML for response with gather.
     */
    public function generateResponseTwiML(string $message, bool $expectReply = true): string
    {
        $response = new \SimpleXMLElement('<Response/>');

        $say = $response->addChild('Say');
        $say->addAttribute('voice', config('telephony.tts.voice', 'Polly.Joanna'));
        $say[0] = $message;

        if ($expectReply) {
            $gather = $response->addChild('Gather');
            $gather->addAttribute('input', 'speech');
            $gather->addAttribute('timeout', (string) config('telephony.agent.gather_timeout', 5));
            $gather->addAttribute('speechTimeout', config('telephony.agent.gather_speech_timeout', 'auto'));
            $gather->addAttribute('action', '/voice/gather');
            $gather->addAttribute('method', 'POST');
        } else {
            $response->addChild('Hangup');
        }

        return $response->asXML();
    }

    /**
     * Transfer call to another number.
     */
    public function transferCall(string $callSid, string $toNumber, ?string $message = null): string
    {
        $response = new \SimpleXMLElement('<Response/>');

        if ($message) {
            $say = $response->addChild('Say');
            $say->addAttribute('voice', config('telephony.tts.voice', 'Polly.Joanna'));
            $say[0] = $message;
        }

        $dial = $response->addChild('Dial');
        $dial[0] = $toNumber;

        return $response->asXML();
    }

    /**
     * Initiate outbound call via Twilio API.
     */
    public function initiateCall(string $tenantId, string $toNumber, string $fromNumber, ?string $agentId = null): ?array
    {
        if ($this->provider !== 'twilio') {
            Log::error('Outbound calls only supported with Twilio');
            return null;
        }

        try {
            $response = Http::withBasicAuth(
                $this->config['sid'],
                $this->config['auth_token']
            )->post('https://api.twilio.com/2010-04-01/Accounts/' . $this->config['sid'] . '/Calls.json', [
                'To' => $toNumber,
                'From' => $fromNumber,
                'Url' => url('/voice/inbound'),
                'StatusCallback' => $this->config['status_callback'],
                'StatusCallbackEvent' => ['initiated', 'ringing', 'answered', 'completed'],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $call = $this->logCall(
                    $tenantId,
                    $data['sid'],
                    $fromNumber,
                    $toNumber,
                    'outbound',
                    'initiated'
                );
                return ['call_sid' => $data['sid'], 'call' => $call];
            }
        } catch (\Exception $e) {
            Log::error('Failed to initiate call', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Normalize phone number to E.164 format.
     */
    private function normalizePhone(string $number): string
    {
        // Basic normalization - remove non-numeric except leading +
        $normalized = preg_replace('/[^0-9+]/', '', $number);

        // Add US country code if missing
        if (!str_starts_with($normalized, '+') && strlen($normalized) === 10) {
            $normalized = '+1' . $normalized;
        }

        return $normalized;
    }
}
