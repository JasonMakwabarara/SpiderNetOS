<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\VoiceNumber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * VoiceSafetyGuard — Phase A
 *
 * Single entry-point that gates every voice action through three checks:
 *
 *   1. FeatureFlag    – is the voice vertical live for this tenant?
 *   2. CostGovernor   – will this action blow the budget?
 *   3. ApprovalEngine – does this tool require human approval?
 *
 * Used by VoiceController at inbound time and by ToolRegistry at tool-execute time.
 *
 * Usage:
 *   $guard = app(VoiceSafetyGuard::class);
 *   $result = $guard->checkInbound($tenantId);
 *   if (!$result['allowed']) { ... return blocked TwiML ... }
 *
 *   $result = $guard->checkTool($tenantId, 'send_sms', ['to_number' => '+1...', 'message' => '...']);
 */
class VoiceSafetyGuard
{
    /** Tools that always require approval on strict-policy tenants */
    private const APPROVAL_TOOLS = ['transfer_call', 'send_sms'];

    /** Estimated cost per tool invocation (USD) */
    private const TOOL_COSTS = [
        'transfer_call'    => 0.002,
        'calendar_booking' => 0.000,
        'send_sms'         => 0.0075,
        'end_call'         => 0.000,
        'hold_call'        => 0.000,
        'record_call_note' => 0.000,
    ];

    /** Base cost estimate per inbound call turn */
    private const TURN_COST = 0.005;

    public function __construct(
        private readonly CostGovernor   $costGovernor,
        private readonly FeatureFlag    $featureFlag,
        private readonly ApprovalEngine $approvalEngine,
    ) {}

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Gate an inbound call.
     *
     * @return array{allowed: bool, reason: string|null, degraded: bool}
     */
    public function checkInbound(string $tenantId): array
    {
        // 1. Feature flag
        if (!FeatureFlag::on('voice.inbound', $tenantId)) {
            return $this->deny('feature_flag_off');
        }

        // 2. Cost governor
        $cost = $this->costGovernor->canExecute($tenantId, self::TURN_COST);
        if (!$cost['allowed']) {
            Log::warning('voice.inbound_cost_blocked', ['tenant_id' => $tenantId, 'cost' => $cost]);
            return $this->deny('cost_cap_exceeded', $cost['degraded'] ?? false);
        }

        $this->emitMetric('voice_calls_total', $tenantId, ['status' => 'allowed']);
        return ['allowed' => true, 'reason' => null, 'degraded' => $cost['degraded'] ?? false];
    }

    /**
     * Gate a tool invocation for an active call.
     *
     * @param  string $tenantId
     * @param  string $toolId        e.g. 'send_sms'
     * @param  array  $params        tool params (for cost estimate)
     * @param  string $callSid       for approval context
     * @param  string $approvalPolicy 'off' | 'notify' | 'strict'
     * @return array{allowed: bool, awaiting_approval: bool, approval_id: string|null, reason: string|null}
     */
    public function checkTool(
        string $tenantId,
        string $toolId,
        array  $params = [],
        string $callSid = '',
        string $approvalPolicy = 'off',
    ): array {
        // 1. Feature flag for tool execution
        if (!FeatureFlag::on('voice.tools', $tenantId)) {
            return array_merge($this->deny('tool_feature_flag_off'), ['awaiting_approval' => false, 'approval_id' => null]);
        }

        // 2. Cost estimate
        $toolCost = self::TOOL_COSTS[$toolId] ?? 0.001;

        // SMS: multiply by segment count estimate
        if ($toolId === 'send_sms') {
            $msgLen = strlen($params['message'] ?? '');
            $toolCost *= max(1, (int) ceil($msgLen / 160));
        }

        $cost = $this->costGovernor->canExecute($tenantId, $toolCost);
        if (!$cost['allowed']) {
            Log::warning('voice.tool_cost_blocked', ['tenant_id' => $tenantId, 'tool' => $toolId]);
            return array_merge($this->deny('cost_cap_exceeded'), ['awaiting_approval' => false, 'approval_id' => null]);
        }

        // 3. Approval check
        if ($approvalPolicy === 'strict' && in_array($toolId, self::APPROVAL_TOOLS)) {
            $approvalId = $this->requestApproval($tenantId, $toolId, $params, $callSid);
            Log::info('voice.tool_approval_requested', [
                'tenant_id'   => $tenantId,
                'tool'        => $toolId,
                'approval_id' => $approvalId,
            ]);
            return [
                'allowed'           => false,
                'awaiting_approval' => true,
                'approval_id'       => $approvalId,
                'reason'            => 'awaiting_approval',
                'degraded'          => false,
            ];
        }

        $this->emitToolMetric($tenantId, $toolId, 'allowed');
        return [
            'allowed'           => true,
            'awaiting_approval' => false,
            'approval_id'       => null,
            'reason'            => null,
            'degraded'          => $cost['degraded'] ?? false,
        ];
    }

    /**
     * Estimate cost per SMS params (called from VoiceController / tools).
     */
    public function estimateSmsCost(string $message): float
    {
        return self::TOOL_COSTS['send_sms'] * max(1, (int) ceil(strlen($message) / 160));
    }

    /**
     * Resolve the approval policy for a voice number (cached by call_sid in Redis).
     */
    public function approvalPolicyForNumber(string $phoneNumber): string
    {
        try {
            $vn = VoiceNumber::where('phone_number', $phoneNumber)->select('approval_policy')->first();
            return $vn?->approval_policy ?? 'off';
        } catch (\Throwable) {
            return 'off';
        }
    }

    /**
     * Gate Atlas speech (plan D7 §7, POST /api/atlas/speak).
     *
     *   1. The voice vertical's global kill switch (`voice.inbound`, see
     *      config/features.php — `php artisan feature:set voice.inbound off`
     *      silences Atlas's voice as well as calls).
     *   2. CostGovernor — the estimated TTS cost must fit the tenant's budget.
     *
     * The per-surface flag `voice.atlas_speak` is checked by AtlasSpeechService.
     *
     * @return array{allowed: bool, reason: string|null, degraded: bool}
     */
    public function checkSpeech(string $tenantId, float $estimatedCost = 0.0): array
    {
        if (!FeatureFlag::on('voice.inbound', $tenantId)) {
            return $this->deny('voice_killed');
        }

        $cost = $this->costGovernor->canExecute($tenantId, $estimatedCost);
        if (!$cost['allowed']) {
            Log::warning('voice.speech_cost_blocked', ['tenant_id' => $tenantId, 'estimated_cost' => $estimatedCost]);
            return $this->deny('cost_cap_exceeded', $cost['degraded'] ?? false);
        }

        return ['allowed' => true, 'reason' => null, 'degraded' => $cost['degraded'] ?? false];
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function deny(string $reason, bool $degraded = false): array
    {
        return ['allowed' => false, 'reason' => $reason, 'degraded' => $degraded];
    }

    private function requestApproval(string $tenantId, string $toolId, array $params, string $callSid): string
    {
        try {
            $record = $this->approvalEngine->createApproval(
                tenantId:     $tenantId,
                requesterId:  'voice_agent',
                type:         'manual',
                resourceType: 'voice_tool',
                resourceId:   $toolId . ':' . $callSid,
                reason:       "Voice tool '{$toolId}' requires approval (strict policy)",
                context:      ['params' => $params, 'call_sid' => $callSid],
            );
            return $record['id'] ?? (string) Str::uuid();
        } catch (\Throwable $e) {
            Log::error('voice.approval_creation_failed', ['tool' => $toolId, 'error' => $e->getMessage()]);
            return (string) Str::uuid();
        }
    }

    private function emitMetric(string $metric, string $tenantId, array $labels = []): void
    {
        try {
            $key = "metrics:{$metric}:tenant:{$tenantId}";
            \Illuminate\Support\Facades\Redis::incr($key);
        } catch (\Throwable) {
            // non-critical
        }
    }

    private function emitToolMetric(string $tenantId, string $toolId, string $status): void
    {
        try {
            \Illuminate\Support\Facades\Redis::incr("metrics:voice_tool_invocations_total:tenant:{$tenantId}:tool:{$toolId}:status:{$status}");
        } catch (\Throwable) {
            // non-critical
        }
    }
}
