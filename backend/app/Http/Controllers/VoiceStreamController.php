<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VoiceCall;
use App\Services\FeatureFlag;
use App\Services\TelephonyService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * VoiceStreamController — Phase C
 *
 * Handles the Twilio Media Streams handshake:
 *
 *   1. POST /voice/stream/connect  — Twilio sends this when the call starts.
 *      We return TwiML <Connect><Stream> to upgrade the call to a bidirectional
 *      WebSocket media stream.
 *
 *   2. The inference service WebSocket at /voice/stream then handles
 *      the bidirectional mulaw-8kHz audio frames via voice_pipeline.py.
 *
 * Feature flag: voice.streaming must be on (per-tenant or globally).
 * Kill-switch: set voice.streaming=off → falls back to Gather-mode TwiML.
 *
 * Latency KPI: p95 first-audio-out ≤ 1.5 s from stream established.
 */
class VoiceStreamController extends Controller
{
    public function __construct(
        private readonly TelephonyService $telephony,
    ) {}

    /**
     * POST /voice/stream/connect
     *
     * Returns TwiML that tells Twilio to upgrade the call to a Media Stream.
     * Called immediately after the inbound TwiML's <Pause> or <Say> completes.
     */
    public function connect(Request $request): Response
    {
        $validated = $request->validate([
            'CallSid' => 'required|string',
            'From' => 'required|string',
            'To' => 'required|string',
        ]);

        $callSid = $validated['CallSid'];
        $fromNumber = $validated['From'];
        $toNumber = $validated['To'];

        $resolved = $this->telephony->resolveTenant($toNumber, $fromNumber);
        $tenantId = $resolved['tenant_id'] ?? null;

        // Kill-switch: if flag off, fall back to Gather mode
        if (! FeatureFlag::on('voice.streaming', $tenantId)) {
            Log::info('voice.stream.fallback_to_gather', ['call_sid' => $callSid]);

            return $this->twimlResponse($this->gatherFallbackTwiML());
        }

        Log::info('voice.stream.connect', [
            'call_sid' => $callSid,
            'tenant_id' => $tenantId,
        ]);

        // Record stream session start
        $this->recordStreamStart($callSid);

        // Emit observability event
        $this->emitEvent('voice.stream.connect', [
            'call_sid' => $callSid,
            'tenant_id' => $tenantId,
        ]);

        $wsUrl = $this->buildStreamUrl($callSid, $tenantId);

        $twiml = $this->buildStreamTwiML($wsUrl);

        return $this->twimlResponse($twiml);
    }

    // ─── TwiML builders ──────────────────────────────────────────────────────

    /**
     * Build <Connect><Stream> TwiML that upgrades the call to Media Streams.
     *
     * The stream URL points to the inference FastAPI WebSocket endpoint.
     * Custom parameters are passed so voice_pipeline.py can resolve the tenant.
     */
    private function buildStreamTwiML(string $wsUrl): string
    {
        $response = new \SimpleXMLElement('<Response/>');

        // Brief greeting while WS connects
        $say = $response->addChild('Say');
        $say->addAttribute('voice', config('telephony.tts.voice', 'Polly.Joanna'));
        $say[0] = 'Connected.';

        $connect = $response->addChild('Connect');
        $stream = $connect->addChild('Stream');
        $stream->addAttribute('url', $wsUrl);
        $stream->addAttribute('track', 'inbound_track');   // or both_tracks for full duplex

        // Custom parameters forwarded to voice_pipeline.py via the start message
        $params = [
            'tenant_id' => '',  // resolved by pipeline from call_sid
            'stt_provider' => config('telephony.streaming.stt_provider', 'deepgram'),
            'tts_provider' => config('telephony.streaming.tts_provider', 'elevenlabs'),
            'vad_threshold' => (string) config('telephony.streaming.vad_threshold', 0.6),
        ];

        foreach ($params as $name => $value) {
            $param = $stream->addChild('Parameter');
            $param->addAttribute('name', $name);
            $param->addAttribute('value', $value);
        }

        return $response->asXML();
    }

    /**
     * Fallback when streaming is disabled — return standard Gather TwiML.
     */
    private function gatherFallbackTwiML(): string
    {
        $response = new \SimpleXMLElement('<Response/>');
        $say = $response->addChild('Say');
        $say->addAttribute('voice', config('telephony.tts.voice', 'Polly.Joanna'));
        $say[0] = 'Hello! How can I help you today?';

        $gather = $response->addChild('Gather');
        $gather->addAttribute('input', 'speech');
        $gather->addAttribute('timeout', (string) config('telephony.agent.gather_timeout', 5));
        $gather->addAttribute('speechTimeout', config('telephony.agent.gather_speech_timeout', 'auto'));
        $gather->addAttribute('action', '/api/voice/gather');
        $gather->addAttribute('method', 'POST');

        return $response->asXML();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function buildStreamUrl(string $callSid, ?string $tenantId): string
    {
        $baseWs = config('telephony.streaming.websocket_url', 'wss://your-domain.com/voice/stream');

        return $baseWs.'?call_sid='.urlencode($callSid);
    }

    private function recordStreamStart(string $callSid): void
    {
        try {
            VoiceCall::where('call_sid', $callSid)
                ->update([
                    'stream_session_id' => uniqid('stream_', true),
                ]);
        } catch (\Throwable $e) {
            Log::warning('voice.stream.record_start_failed', ['error' => $e->getMessage()]);
        }
    }

    private function emitEvent(string $type, array $payload): void
    {
        try {
            Redis::publish('events:voice', json_encode([
                'event_type' => $type,
                'payload' => $payload,
                'timestamp' => now()->toIso8601String(),
            ]));
        } catch (\Throwable) {
            // non-critical
        }
    }

    private function twimlResponse(string $twiml): Response
    {
        return response($twiml, 200, ['Content-Type' => 'application/xml']);
    }
}
