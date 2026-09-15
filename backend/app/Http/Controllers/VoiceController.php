<?php

namespace App\Http\Controllers;

use App\Models\VoiceCall;
use App\Models\VoiceNumber;
use App\Services\TelephonyService;
use App\Services\MetaPlanner;
use App\Services\CostGovernor;
use App\Services\VoiceSafetyGuard;
use App\Services\FeatureFlag;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Http;

/**
 * VoiceController — Telephony webhook handler for SpiderNet OS Voice AI
 *
 * Handles Twilio webhooks for inbound calls, speech gathering, and status callbacks.
 * Implements Gather Mode first (Twilio STT) with upgrade path to Stream Mode (Phase C).
 *
 * Phases:
 *   A — hardened with VoiceSafetyGuard, VerifyTwilioSignature, VoiceFeatureFlag
 *   B — dispatch via VoiceAgent when voice.agent_mode flag is on
 *   D — show / export / replay endpoints
 */
class VoiceController extends Controller
{
    private TelephonyService $telephony;
    private MetaPlanner $metaPlanner;
    private CostGovernor $costGovernor;
    private VoiceSafetyGuard $safetyGuard;

    public function __construct(
        TelephonyService $telephony,
        MetaPlanner $metaPlanner,
        CostGovernor $costGovernor,
        VoiceSafetyGuard $safetyGuard
    ) {
        $this->telephony   = $telephony;
        $this->metaPlanner = $metaPlanner;
        $this->costGovernor = $costGovernor;
        $this->safetyGuard = $safetyGuard;
    }

    // ─── Twilio Webhooks ─────────────────────────────────────────────────────

    /**
     * POST /voice/inbound
     *
     * Twilio calls this when a call comes in.
     * Returns TwiML with greeting and Gather for speech input.
     */
    public function inbound(Request $request): Response
    {
        $validated = $request->validate([
            'CallSid'    => 'required|string',
            'From'       => 'required|string',
            'To'         => 'required|string',
            'CallStatus' => 'nullable|string',
        ]);

        $callSid    = $validated['CallSid'];
        $fromNumber = $validated['From'];
        $toNumber   = $validated['To'];

        Log::info('voice.inbound.received', [
            'call_sid' => $callSid,
            'from'     => $fromNumber,
            'to'       => $toNumber,
        ]);

        // Emit observability event
        $this->emitEvent('voice.inbound.received', [
            'call_sid'   => $callSid,
            'from'       => $fromNumber,
            'to'         => $toNumber,
        ]);

        // Resolve tenant and agent configuration
        $resolved = $this->telephony->resolveTenant($toNumber, $fromNumber);

        if (!$resolved) {
            $twiml = $this->telephony->generateGreetingTwiML(
                'Thank you for calling. The number you have dialed is not currently configured. Please contact support.',
                null
            );
            return $this->twimlResponse($twiml);
        }

        $tenantId   = $resolved['tenant_id'];
        $agentId    = $resolved['agent_id'];
        $voiceConfig = $resolved['config'];

        // Safety guard: feature flag + cost check
        $safety = $this->safetyGuard->checkInbound($tenantId);
        if (!$safety['allowed']) {
            Log::warning('voice.inbound_blocked', [
                'call_sid'  => $callSid,
                'tenant_id' => $tenantId,
                'reason'    => $safety['reason'],
            ]);
            $twiml = $this->telephony->generateGreetingTwiML(
                'Thank you for calling. Our system is currently unavailable. Please try again later.',
                null
            );
            return $this->twimlResponse($twiml);
        }

        // Snapshot feature flags for audit
        $flagSnapshot = [
            'voice.inbound'    => FeatureFlag::on('voice.inbound', $tenantId),
            'voice.agent_mode' => FeatureFlag::on('voice.agent_mode', $tenantId),
            'voice.streaming'  => FeatureFlag::on('voice.streaming', $tenantId),
        ];

        // Log the call with flag snapshot
        $this->telephony->logCall(
            $tenantId,
            $callSid,
            $toNumber,
            $fromNumber,
            'inbound',
            'ringing',
            [
                'agent_id'      => $agentId,
                'config'        => $voiceConfig,
                'flag_snapshot' => $flagSnapshot,
            ]
        );

        // Persist flag snapshot to dedicated column
        VoiceCall::where('call_sid', $callSid)
            ->update(['tenant_flag_snapshot' => $flagSnapshot]);

        $greeting     = $voiceConfig['greeting'] ?? 'Hello! Thank you for calling. How can I help you today?';
        $gatherPrompt = "I'm listening. Please tell me how I can assist you.";

        $twiml = $this->telephony->generateGreetingTwiML($greeting, $gatherPrompt);

        return $this->twimlResponse($twiml);
    }

    /**
     * POST /voice/gather
     *
     * Receives speech recognition result from Twilio.
     * Phase A: direct inference; Phase B: via VoiceAgent.
     */
    public function gather(Request $request): Response
    {
        $validated = $request->validate([
            'CallSid'      => 'required|string',
            'SpeechResult' => 'nullable|string',
            'Confidence'   => 'nullable|numeric',
            'From'         => 'required|string',
            'To'           => 'required|string',
        ]);

        $callSid      = $validated['CallSid'];
        $speechResult = $validated['SpeechResult'] ?? '';
        $confidence   = $validated['Confidence'] ?? 0.0;
        $fromNumber   = $validated['From'];
        $toNumber     = $validated['To'];

        // If no speech detected, prompt again
        if (empty($speechResult)) {
            $twiml = $this->telephony->generateResponseTwiML(
                "I didn't catch that. Could you please repeat?",
                true
            );
            return $this->twimlResponse($twiml);
        }

        // Log transcript turn
        $this->telephony->appendTranscript($callSid, 'caller', $speechResult);

        Log::info('voice.turn.started', [
            'call_sid'   => $callSid,
            'confidence' => $confidence,
            'speech_len' => strlen($speechResult),
        ]);

        // Resolve tenant
        $resolved = $this->telephony->resolveTenant($toNumber, $fromNumber);
        if (!$resolved) {
            return $this->twimlResponse(
                $this->telephony->generateResponseTwiML(
                    "I'm having trouble connecting. Please try again later.",
                    false
                )
            );
        }

        $tenantId   = $resolved['tenant_id'];
        $agentId    = $resolved['agent_id'];
        $voiceConfig = $resolved['config'] ?? [];

        $startMs = (int) (microtime(true) * 1000);

        try {
            // Phase B: route through VoiceAgent when flag is on
            if (FeatureFlag::on('voice.agent_mode', $tenantId)) {
                $response = $this->processViaVoiceAgent(
                    $tenantId,
                    $agentId,
                    $callSid,
                    $speechResult,
                    $fromNumber,
                    $voiceConfig
                );
            } else {
                // Phase A: direct inference (preserved behaviour)
                $response = $this->processVoiceIntent(
                    $tenantId,
                    $agentId,
                    $callSid,
                    $speechResult,
                    $voiceConfig
                );
            }

            $latencyMs = (int) (microtime(true) * 1000) - $startMs;

            // Append agent response to transcript
            if (!empty($response['text'])) {
                $this->telephony->appendTranscript($callSid, 'agent', $response['text']);
            }

            $this->emitEvent('voice.turn.completed', [
                'call_sid'   => $callSid,
                'tenant_id'  => $tenantId,
                'latency_ms' => $latencyMs,
                'model'      => $response['model'] ?? 'qwen3',
                'tokens'     => $response['tokens_used'] ?? 0,
            ]);

            $this->emitLatencyMetric($tenantId, $latencyMs);

            $expectReply = $response['continue_conversation'] ?? true;
            $twiml = $this->telephony->generateResponseTwiML(
                $response['text'] ?? "I'm sorry, I didn't understand that. Could you rephrase?",
                $expectReply
            );

            return $this->twimlResponse($twiml);

        } catch (\Exception $e) {
            Log::error('voice.turn.failed', [
                'call_sid' => $callSid,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            // Update call record with error code
            VoiceCall::where('call_sid', $callSid)
                ->update(['error_code' => 'INFERENCE_FAILED:' . substr($e->getMessage(), 0, 50)]);

            $twiml = $this->telephony->generateResponseTwiML(
                "I'm having a technical issue. Let me transfer you to a representative.",
                false
            );

            return $this->twimlResponse($twiml);
        }
    }

    /**
     * POST /voice/status
     *
     * Handles call status callbacks from Twilio.
     */
    public function status(Request $request): Response
    {
        $validated = $request->validate([
            'CallSid'      => 'required|string',
            'CallStatus'   => 'required|string',
            'CallDuration' => 'nullable|integer',
            'RecordingUrl' => 'nullable|string',
        ]);

        $callSid  = $validated['CallSid'];
        $status   = $validated['CallStatus'];
        $duration = $validated['CallDuration'] ?? null;

        Log::info('voice.call.status', [
            'call_sid' => $callSid,
            'status'   => $status,
            'duration' => $duration,
        ]);

        $this->telephony->updateCallStatus($callSid, $status, $duration);

        if ($status === 'completed') {
            $this->emitEvent('voice.call.ended', [
                'call_sid'   => $callSid,
                'status'     => $status,
                'duration_s' => $duration,
            ]);
            $this->triggerPostCallProcessing($callSid);
        }

        return response('OK', 200);
    }

    /**
     * POST /voice/recording  (Phase A)
     *
     * Twilio posts this when a recording completes.
     * Stores the recording URL in voice_calls.metadata.
     */
    public function recording(Request $request): Response
    {
        $validated = $request->validate([
            'CallSid'            => 'required|string',
            'RecordingUrl'       => 'required|string',
            'RecordingSid'       => 'nullable|string',
            'RecordingDuration'  => 'nullable|integer',
        ]);

        $callSid      = $validated['CallSid'];
        $recordingUrl = $validated['RecordingUrl'];

        Log::info('voice.recording.received', [
            'call_sid'      => $callSid,
            'recording_sid' => $validated['RecordingSid'] ?? null,
            'duration'      => $validated['RecordingDuration'] ?? null,
        ]);

        $call = VoiceCall::where('call_sid', $callSid)->first();
        if ($call) {
            $meta = $call->metadata ?? [];
            $meta['recording_url']      = $recordingUrl;
            $meta['recording_sid']      = $validated['RecordingSid'] ?? null;
            $meta['recording_duration'] = $validated['RecordingDuration'] ?? null;
            $call->metadata = $meta;
            $call->save();
        }

        return response('OK', 200);
    }

    // ─── Authenticated management endpoints ──────────────────────────────────

    /**
     * POST /voice/call
     *
     * Initiate outbound call.
     */
    public function initiateCall(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'to_number'   => 'required|string',
            'from_number' => 'required|string',
            'agent_id'    => 'nullable|string',
        ]);

        $tenantId = $request->user()->tenant_id;

        $voiceNumber = VoiceNumber::where('tenant_id', $tenantId)
            ->where('phone_number', $validated['from_number'])
            ->where('is_active', true)
            ->first();

        if (!$voiceNumber) {
            return response()->json(['success' => false, 'error' => 'Invalid from_number for this tenant'], 422);
        }

        if (!$voiceNumber->allow_outbound) {
            return response()->json(['success' => false, 'error' => 'Outbound calls are not enabled for this number'], 403);
        }

        $result = $this->telephony->initiateCall(
            $tenantId,
            $validated['to_number'],
            $validated['from_number'],
            $validated['agent_id'] ?? $voiceNumber->agent_id
        );

        if ($result) {
            return response()->json([
                'success'  => true,
                'call_sid' => $result['call_sid'],
                'call_id'  => $result['call']->id,
            ]);
        }

        return response()->json(['success' => false, 'error' => 'Failed to initiate call'], 500);
    }

    /**
     * GET /voice/calls
     *
     * List call history for tenant.
     */
    public function listCalls(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $perPage  = min((int) $request->input('per_page', 20), 100);

        $calls = VoiceCall::where('tenant_id', $tenantId)
            ->orderBy('started_at', 'desc')
            ->paginate($perPage);

        return response()->json($calls);
    }

    /**
     * GET /voice/calls/{id}  (Phase D)
     *
     * Retrieve a single call with transcript and summary.
     */
    public function showCall(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $call = VoiceCall::with('summary')
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json($call);
    }

    /**
     * GET /voice/calls/{id}/transcript.txt  (Phase D)
     */
    public function exportTranscript(Request $request, int $id): Response
    {
        $tenantId = $request->user()->tenant_id;

        $call = VoiceCall::where('tenant_id', $tenantId)->findOrFail($id);

        $lines = [];
        foreach ($call->transcript ?? [] as $entry) {
            $speaker = strtoupper($entry['speaker'] ?? 'unknown');
            $lines[] = "[{$entry['timestamp']}] {$speaker}: {$entry['text']}";
        }

        return response(implode("\n", $lines), 200, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"call-{$id}-transcript.txt\"",
        ]);
    }

    // ─── Voice Numbers Management (Phase D) ─────────────────────────────────

    /**
     * GET /voice/numbers
     */
    public function listNumbers(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $numbers  = VoiceNumber::where('tenant_id', $tenantId)->get();
        return response()->json(['data' => $numbers]);
    }

    /**
     * POST /voice/numbers
     */
    public function createNumber(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenantId  = $request->user()->tenant_id;
        $validated = $request->validate([
            'phone_number'    => 'required|string|max:20',
            'provider'        => 'nullable|string|in:twilio,signalwire,vonage',
            'provider_sid'    => 'nullable|string|max:100',
            'agent_id'        => 'nullable|string|max:100',
            'config'          => 'nullable|array',
            'tool_allowlist'  => 'nullable|array',
            'approval_policy' => 'nullable|string|in:off,notify,strict',
            'allow_outbound'  => 'nullable|boolean',
        ]);

        $number = VoiceNumber::create(array_merge($validated, ['tenant_id' => $tenantId]));

        return response()->json(['data' => $number], 201);
    }

    /**
     * PATCH /voice/numbers/{id}
     */
    public function updateNumber(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $number   = VoiceNumber::where('tenant_id', $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'agent_id'        => 'nullable|string|max:100',
            'config'          => 'nullable|array',
            'tool_allowlist'  => 'nullable|array',
            'approval_policy' => 'nullable|string|in:off,notify,strict',
            'allow_outbound'  => 'nullable|boolean',
            'is_active'       => 'nullable|boolean',
            'daily_call_cap'  => 'nullable|integer|min:0',
        ]);

        $number->update($validated);

        return response()->json(['data' => $number]);
    }

    /**
     * DELETE /voice/numbers/{id}
     */
    public function deleteNumber(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        VoiceNumber::where('tenant_id', $tenantId)->findOrFail($id)->delete();
        return response()->json(null, 204);
    }

    // ─── Voice Quotas (Phase D) ──────────────────────────────────────────────

    /**
     * GET /voice/quotas
     */
    public function getQuotas(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $quota    = \App\Models\VoiceQuota::firstOrCreate(
            ['tenant_id' => $tenantId],
            ['monthly_minutes_cap' => 0, 'monthly_minutes_used' => 0, 'outbound_cap' => 0, 'sms_cap' => 0]
        );
        return response()->json(['data' => $quota]);
    }

    /**
     * PUT /voice/quotas
     */
    public function updateQuotas(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenantId  = $request->user()->tenant_id;
        $validated = $request->validate([
            'monthly_minutes_cap' => 'required|integer|min:0',
            'outbound_cap'        => 'required|integer|min:0',
            'sms_cap'             => 'required|integer|min:0',
        ]);

        $quota = \App\Models\VoiceQuota::firstOrCreate(['tenant_id' => $tenantId]);
        $quota->update($validated);

        return response()->json(['data' => $quota]);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * Phase B: route through the Python VoiceAgent via inference service.
     */
    private function processViaVoiceAgent(
        string $tenantId,
        string $agentId,
        string $callSid,
        string $callerInput,
        string $fromNumber,
        array  $config
    ): array {
        $inferenceUrl = config('services.inference.url', 'http://inference:9000');

        $response = Http::timeout(8)
            ->post("{$inferenceUrl}/voice/agent", [
                'tenant_id'    => $tenantId,
                'agent_id'     => $agentId,
                'call_sid'     => $callSid,
                'caller_input' => $callerInput,
                'caller_number'=> $fromNumber,
                'config'       => $config,
            ]);

        if (!$response->successful()) {
            // Fall back to direct inference on agent service failure
            Log::warning('voice.agent_service_failed, falling back', [
                'call_sid' => $callSid,
                'status'   => $response->status(),
            ]);
            return $this->processVoiceIntent($tenantId, $agentId, $callSid, $callerInput, $config);
        }

        return $response->json();
    }

    /**
     * Phase A (preserved): direct synchronous inference for fast response.
     */
    private function processVoiceIntent(
        string $tenantId,
        string $agentId,
        string $callSid,
        string $callerInput,
        array  $config
    ): array {
        $inferenceUrl = config('services.inference.url', 'http://inference:9000');

        $systemPrompt  = $config['system_prompt'] ?? 'You are a helpful voice assistant. Be concise and natural.';
        $businessName  = $config['business_name'] ?? 'our business';
        $prompt        = str_replace('{business_name}', $businessName, $systemPrompt);

        $response = Http::timeout(5)
            ->post("{$inferenceUrl}/generate", [
                'model'       => 'qwen3',
                'messages'    => [
                    ['role' => 'system', 'content' => $prompt],
                    ['role' => 'user',   'content' => $callerInput],
                ],
                'temperature' => $config['temperature'] ?? 0.4,
                'max_tokens'  => $config['max_tokens']  ?? 150,
            ]);

        if (!$response->successful()) {
            throw new \Exception('Inference service failed: ' . $response->body());
        }

        $result = $response->json();
        $text   = $result['choices'][0]['message']['content'] ?? '';

        $actions = [];
        if (str_contains($text, '[TRANSFER]')) { $actions[] = 'transfer';    $text = str_replace('[TRANSFER]', '', $text); }
        if (str_contains($text, '[SMS]'))      { $actions[] = 'send_sms';   $text = str_replace('[SMS]', '', $text); }
        if (str_contains($text, '[END]'))      { $actions[] = 'end_call';   $text = str_replace('[END]', '', $text); }

        return [
            'text'                  => trim($text),
            'actions'               => $actions,
            'continue_conversation' => !in_array('end_call', $actions),
            'model'                 => 'qwen3',
            'tokens_used'           => $result['usage']['total_tokens'] ?? 0,
        ];
    }

    /**
     * Trigger post-call processing.
     *
     * Phase A: hands the call to the intelligence workers over Redis.
     * Phase C: also dispatches a Horizon job for structured summary.
     */
    private function triggerPostCallProcessing(string $callSid): void
    {
        try {
            // Resolve tenant for this call (needed for the job)
            $call     = \App\Models\VoiceCall::where('call_sid', $callSid)->select('tenant_id')->first();
            $tenantId = (string) ($call?->tenant_id ?? '');

            // Phase A path: intelligence/main.py consumes agent:dispatch with
            // BLPOP (a list) and reads tenant_id / agent_id from the top level
            // of the envelope — the same shape MetaPlanner::dispatch() pushes.
            // Publish alone never reached it (pub/sub and lists are disjoint
            // keyspaces); publish is kept for passive observers only.
            if ($tenantId !== '') {
                $dispatchMessage = json_encode([
                    'tenant_id' => $tenantId,
                    'agent_id'  => 'nexus',
                    'intent'    => 'voice.post_call_process',
                    'context'   => [
                        'call_sid'     => $callSid,
                        'tenant_id'    => $tenantId,
                        'target_agent' => 'nexus',
                        'channel'      => 'voice',
                    ],
                ]);

                Redis::lpush('agent:dispatch', $dispatchMessage);
                Redis::publish('agent:dispatch', $dispatchMessage);
            } else {
                Log::warning('voice.post_call_dispatch_skipped', [
                    'call_sid' => $callSid,
                    'reason'   => 'no voice_calls row / tenant for this CallSid',
                ]);
            }

            // Phase C path: Laravel Horizon job for structured summary
            \App\Jobs\ProcessVoiceCallSummary::dispatch($callSid, $tenantId)
                ->onQueue('voice-post-call')
                ->delay(now()->addSeconds(3)); // brief delay to ensure DB writes settle

        } catch (\Throwable $e) {
            Log::error('voice.post_call_dispatch_failed', [
                'call_sid' => $callSid,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Emit an event to the event_log via Redis pub/sub.
     */
    private function emitEvent(string $type, array $payload): void
    {
        try {
            Redis::publish('events:voice', json_encode([
                'event_type' => $type,
                'payload'    => $payload,
                'timestamp'  => now()->toIso8601String(),
            ]));
        } catch (\Throwable) {
            // non-critical
        }
    }

    /**
     * Record per-tenant turn latency in Redis for Prometheus scraping.
     */
    private function emitLatencyMetric(string $tenantId, int $latencyMs): void
    {
        try {
            Redis::rpush("metrics:voice_turn_latency_ms:tenant:{$tenantId}", $latencyMs);
            Redis::ltrim("metrics:voice_turn_latency_ms:tenant:{$tenantId}", -1000, -1);
        } catch (\Throwable) {
            // non-critical
        }
    }

    /**
     * Return TwiML as XML response.
     */
    private function twimlResponse(string $twiml): Response
    {
        return response($twiml, 200, ['Content-Type' => 'application/xml']);
    }
}
