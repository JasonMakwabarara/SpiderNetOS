<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\VoiceCall;
use App\Models\VoiceCallSummary;
use App\Services\FeatureFlag;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;

/**
 * ProcessVoiceCallSummary — Phase C
 *
 * Post-call Horizon job that runs after CallStatus=completed.
 * Dispatched by VoiceController::triggerPostCallProcessing() via Redis pub/sub
 * and consumed by the Nexus DAG handler in nexus_agent.py.
 *
 * This PHP Job is the Laravel-side worker:
 *   1. Load call transcript from voice_calls
 *   2. Call inference /generate to produce summary, sentiment, key points, follow-up tasks
 *   3. Persist to voice_call_summaries
 *   4. (Optional) Send email summary
 *   5. (Optional) Sync to CRM via integration adapter
 *
 * Max retries: 3 with exponential backoff. Dead-letter on exhaustion.
 *
 * Kill-switch: voice.post_call_summary=off skips steps 2–5 but still fires.
 */
class ProcessVoiceCallSummary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries    = 3;
    public int $timeout  = 60;
    public int $backoff  = 30;   // seconds

    public function __construct(
        public readonly string $callSid,
        public readonly string $tenantId,
    ) {}

    public function handle(): void
    {
        Log::info('voice.post_call.started', [
            'call_sid'  => $this->callSid,
            'tenant_id' => $this->tenantId,
        ]);

        // Feature flag check
        if (!FeatureFlag::on('voice.post_call_summary', $this->tenantId)) {
            Log::info('voice.post_call.flag_off', ['call_sid' => $this->callSid]);
            return;
        }

        $call = VoiceCall::where('call_sid', $this->callSid)
            ->where('tenant_id', $this->tenantId)
            ->first();

        if (!$call) {
            Log::warning('voice.post_call.call_not_found', ['call_sid' => $this->callSid]);
            return;
        }

        $transcript = $this->buildTranscriptText($call->transcript ?? []);

        if (empty($transcript)) {
            Log::info('voice.post_call.no_transcript', ['call_sid' => $this->callSid]);
            return;
        }

        // ── Call inference for structured summary ─────────────────────────
        $inferenceResult = $this->callInference($transcript, $call->phone_number ?? '');

        if (!$inferenceResult) {
            Log::warning('voice.post_call.inference_failed', ['call_sid' => $this->callSid]);
            // Still create a partial summary row
            $inferenceResult = [
                'summary'       => 'Summary generation failed — see transcript.',
                'key_points'    => [],
                'follow_up_tasks' => [],
                'sentiment'     => 'neutral',
            ];
        }

        // ── Persist summary row ───────────────────────────────────────────
        $summary = VoiceCallSummary::updateOrCreate(
            ['voice_call_id' => $call->id],
            [
                'tenant_id'       => $this->tenantId,
                'summary'         => $inferenceResult['summary'],
                'key_points'      => $inferenceResult['key_points'] ?? [],
                'follow_up_tasks' => $inferenceResult['follow_up_tasks'] ?? [],
                'sentiment'       => $inferenceResult['sentiment'] ?? 'neutral',
                'processed_at'    => now(),
            ]
        );

        Log::info('voice.post_call.summary_saved', [
            'call_sid'   => $this->callSid,
            'summary_id' => $summary->id,
            'sentiment'  => $summary->sentiment,
        ]);

        $this->emitEvent('voice.summary.generated', [
            'call_sid'   => $this->callSid,
            'tenant_id'  => $this->tenantId,
            'summary_id' => $summary->id,
            'sentiment'  => $summary->sentiment,
        ]);

        // ── Optional: email summary ───────────────────────────────────────
        if (config('telephony.post_call.email_summary') && !empty($call->metadata['contact_email'])) {
            $this->sendEmailSummary($call, $summary);
        }

        // ── Optional: CRM sync ────────────────────────────────────────────
        if (config('telephony.post_call.crm_sync') && FeatureFlag::on('voice.crm_sync', $this->tenantId)) {
            $this->syncToCrm($call, $summary);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('voice.post_call.job_failed', [
            'call_sid'  => $this->callSid,
            'tenant_id' => $this->tenantId,
            'error'     => $exception->getMessage(),
        ]);

        // Mark in voice_call_summaries as failed for visibility
        try {
            VoiceCallSummary::updateOrCreate(
                ['voice_call_id' => VoiceCall::where('call_sid', $this->callSid)->value('id')],
                [
                    'tenant_id'  => $this->tenantId,
                    'summary'    => 'Processing failed: ' . $exception->getMessage(),
                    'sentiment'  => 'neutral',
                    'processed_at' => now(),
                ]
            );
        } catch (\Throwable) {
            // best-effort
        }
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function buildTranscriptText(array $transcript): string
    {
        $lines = [];
        foreach ($transcript as $entry) {
            $speaker = strtoupper($entry['speaker'] ?? 'UNKNOWN');
            $lines[] = "{$speaker}: {$entry['text']}";
        }
        return implode("\n", $lines);
    }

    private function callInference(string $transcript, string $phoneNumber): ?array
    {
        $inferenceUrl = config('services.inference.url', 'http://inference:9000');

        $prompt = <<<PROMPT
Analyze the following phone call transcript and return a JSON object with these keys:
- "summary": A 2-3 sentence plain-text summary of the call.
- "key_points": Array of strings, each a key point from the call (max 5).
- "follow_up_tasks": Array of strings, each an action item identified (max 3).
- "sentiment": One of "positive", "neutral", "negative" based on caller tone.

Transcript:
{$transcript}

Return ONLY valid JSON, no markdown.
PROMPT;

        try {
            $response = Http::timeout(30)->post("{$inferenceUrl}/generate", [
                'model'    => 'qwen3',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a call center analyst. Return only valid JSON.'],
                    ['role' => 'user',   'content' => $prompt],
                ],
                'temperature' => 0.2,
                'max_tokens'  => 512,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $text = $response->json()['choices'][0]['message']['content'] ?? '';

            // Strip markdown code fences if present
            $text = preg_replace('/```(?:json)?\n?/', '', $text);
            $text = trim($text, " \n`");

            $decoded = json_decode($text, true);
            if (!is_array($decoded)) {
                return null;
            }

            return $decoded;
        } catch (\Throwable $e) {
            Log::warning('voice.post_call.inference_exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function sendEmailSummary(VoiceCall $call, VoiceCallSummary $summary): void
    {
        try {
            $email = $call->metadata['contact_email'] ?? null;
            if (!$email) {
                return;
            }

            // Basic mail — in production use a Mailable class
            Mail::raw(
                "Call Summary\n\nDate: {$call->started_at}\nDuration: {$call->duration_seconds}s\n\n{$summary->summary}",
                function ($m) use ($email) {
                    $m->to($email)->subject('Your Call Summary');
                }
            );

            $summary->update(['email_sent' => true]);

            Log::info('voice.post_call.email_sent', ['call_sid' => $this->callSid]);
        } catch (\Throwable $e) {
            Log::warning('voice.post_call.email_failed', ['error' => $e->getMessage()]);
        }
    }

    private function syncToCrm(VoiceCall $call, VoiceCallSummary $summary): void
    {
        // Phase D: delegate to CrmAdapter
        Log::info('voice.post_call.crm_sync_placeholder', ['call_sid' => $this->callSid]);
    }

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
}
