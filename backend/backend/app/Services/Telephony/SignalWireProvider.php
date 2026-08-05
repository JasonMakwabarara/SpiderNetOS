<?php

namespace App\Services\Telephony;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SignalWire LaML Provider
 *
 * SignalWire is Twilio-compatible — LaML uses the same XML format as TwiML,
 * and the REST API uses the same request/response structure.
 * The only difference is the API base URL and authentication (project_id vs sid).
 *
 * Pricing (US, 2026):
 *   Inbound:  $0.0066/min (vs Twilio $0.0085) — 22% cheaper
 *   Outbound: $0.0080/min (vs Twilio $0.0140) — 43% cheaper
 *
 * API: https://{space}/api/laml/2010-04-01/Accounts/{project_id}/Calls.json
 * Auth: Basic Auth with project_id:auth_token
 */
class SignalWireProvider
{
    private string $projectId;
    private string $authToken;
    private string $space;
    private string $apiBase;
    private string $webhookUrl;
    private string $statusCallback;

    public function __construct(array $config)
    {
        $this->projectId = $config['project_id'] ?? '';
        $this->authToken = $config['auth_token'] ?? '';
        $this->space = $config['space'] ?? '';
        $this->apiBase = $config['api_base'] ?? "https://{$this->space}/api/laml/2010-04-01";
        $this->webhookUrl = $config['webhook_url'] ?? '';
        $this->statusCallback = $config['status_callback'] ?? '';
    }

    /**
     * Initiate an outbound call via SignalWire LaML API.
     *
     * @return array|null ['sid' => string, 'status' => string] or null on failure
     */
    public function initiateCall(string $toNumber, string $fromNumber, array $options = []): ?array
    {
        if (empty($this->projectId) || empty($this->authToken)) {
            Log::error('signalwire.missing_credentials');
            return null;
        }

        $url = "{$this->apiBase}/Accounts/{$this->projectId}/Calls.json";

        $payload = [
            'To' => $toNumber,
            'From' => $fromNumber,
            'Url' => $options['url'] ?? $this->webhookUrl,
            'StatusCallback' => $options['status_callback'] ?? $this->statusCallback,
            'StatusCallbackEvent' => $options['status_callback_events'] ?? ['initiated', 'ringing', 'answered', 'completed'],
        ];

        // Optional: Stream for WebSocket media streaming (Phase C)
        if (!empty($options['stream_url'])) {
            $payload['StreamUrl'] = $options['stream_url'];
        }

        try {
            $response = Http::withBasicAuth($this->projectId, $this->authToken)
                ->timeout(10)
                ->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'sid' => $data['sid'] ?? '',
                    'status' => $data['status'] ?? 'initiated',
                    'direction' => $data['direction'] ?? 'outbound-api',
                ];
            }

            Log::error('signalwire.call_failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('signalwire.call_error', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Get call details from SignalWire.
     */
    public function getCall(string $callSid): ?array
    {
        if (empty($this->projectId) || empty($this->authToken)) {
            return null;
        }

        $url = "{$this->apiBase}/Accounts/{$this->projectId}/Calls/{$callSid}.json";

        try {
            $response = Http::withBasicAuth($this->projectId, $this->authToken)
                ->timeout(5)
                ->get($url);

            return $response->successful() ? $response->json() : null;
        } catch (\Exception $e) {
            Log::error('signalwire.get_call_error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * End an active call.
     */
    public function endCall(string $callSid): bool
    {
        if (empty($this->projectId) || empty($this->authToken)) {
            return false;
        }

        $url = "{$this->apiBase}/Accounts/{$this->projectId}/Calls/{$callSid}.json";

        try {
            $response = Http::withBasicAuth($this->projectId, $this->authToken)
                ->timeout(5)
                ->post($url, ['Status' => 'completed']);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('signalwire.end_call_error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * List recent calls (paginated).
     */
    public function listCalls(int $pageSize = 20, int $page = 0): array
    {
        if (empty($this->projectId) || empty($this->authToken)) {
            return [];
        }

        $url = "{$this->apiBase}/Accounts/{$this->projectId}/Calls.json";

        try {
            $response = Http::withBasicAuth($this->projectId, $this->authToken)
                ->timeout(10)
                ->get($url, [
                    'PageSize' => $pageSize,
                    'Page' => $page,
                ]);

            $data = $response->successful() ? $response->json() : [];
            return $data['calls'] ?? [];
        } catch (\Exception $e) {
            Log::error('signalwire.list_calls_error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Generate LaML Stream TwiML for WebSocket media streaming.
     * SignalWire uses the same <Stream> element as Twilio Media Streams.
     */
    public function generateStreamTwiML(string $streamUrl, array $customParameters = []): string
    {
        $response = new \SimpleXMLElement('<Response/>');

        $say = $response->addChild('Say');
        $say[0] = 'Connecting to AI assistant...';

        $stream = $response->addChild('Stream');
        $stream->addAttribute('url', $streamUrl);

        foreach ($customParameters as $name => $value) {
            $param = $stream->addChild('Parameter');
            $param->addAttribute('name', $name);
            $param->addAttribute('value', $value);
        }

        return $response->asXML();
    }
}
