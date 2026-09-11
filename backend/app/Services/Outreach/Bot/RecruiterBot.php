<?php

declare(strict_types=1);

namespace App\Services\Outreach\Bot;

use App\Models\ConversationMessage;
use App\Models\PartnerProspect;
use App\Models\Tenant;
use App\Services\CostGovernor;
use App\Services\Inference\InferencePlaneClient;
use App\Services\Outreach\OutreachSettings;
use Illuminate\Support\Facades\Log;

/**
 * The recruiter bot: answers one inbound creator email with a draft that a
 * human approves (or, in auto mode, sends). Deterministic gates run before
 * the model, the post-filter runs after it, and anything doubtful becomes a
 * handoff rather than a sent email.
 */
class RecruiterBot
{
    public const SENT_BY = 'recruiter_bot';

    private const REPAIR_SUFFIX = '

Your previous answer was not valid JSON. Reply with the JSON object only: no prose, no markdown fence, no trailing text.';

    private const HANDOFF_PATTERN = '/\b(lawyer|legal|attorney|lawsuit|sue you|gdpr|privacy|data protection|where did you get|how did you get my|complaint|complain|report you|spam|harass|press|journalist|w-?9|w-?8|tax form|contract|invoice|upfront|up-front|retainer|flat fee|per post|exclusive|custom (?:rate|deal|commission)|higher (?:rate|commission)|negotiat)/i';

    public function __construct(
        private readonly OutreachSettings $settings,
        private readonly RecruiterPromptBuilder $prompts,
        private readonly ReplyPostFilter $filter,
        private readonly InferencePlaneClient $inference,
        private readonly OutreachReplyService $replies,
        private readonly CostGovernor $costs,
    ) {}

    /**
     * @return array{outcome: string, reason?: string, message_id?: string, approval_id?: string}
     */
    public function draft(Tenant $tenant, PartnerProspect $prospect, ConversationMessage $inbound): array
    {
        $tenantId = (string) $tenant->id;

        if ($prospect->isTerminal()) {
            return ['outcome' => 'skipped', 'reason' => 'terminal'];
        }
        if ($prospect->bot_paused_at !== null || $prospect->status === PartnerProspect::STATUS_HANDOFF) {
            return ['outcome' => 'skipped', 'reason' => 'paused'];
        }
        if (in_array((string) $inbound->classification, ['opt_out', 'bounce', 'auto_reply'], true)) {
            return ['outcome' => 'skipped', 'reason' => 'classification'];
        }

        $replies = (array) $this->settings->for($tenant)['replies'];
        $mode = ($replies['mode'] ?? 'approve') === 'auto' ? 'auto' : 'approve';

        if (preg_match(self::HANDOFF_PATTERN, (string) $inbound->body) === 1) {
            return $this->replies->handoff($tenant, $prospect, $inbound, 'sensitive_topic');
        }

        $today = now()->toDateString();
        $threadToday = $prospect->bot_replies_day?->toDateString() === $today ? (int) $prospect->bot_replies_today : 0;
        if ($threadToday >= max(1, (int) ($replies['per_thread_daily_cap'] ?? 3))) {
            return $this->replies->handoff($tenant, $prospect, $inbound, 'thread_daily_cap');
        }
        $tenantToday = ConversationMessage::forTenant($tenantId)->where('sent_by', self::SENT_BY)->where('created_at', '>=', now()->startOfDay())->count();
        if ($tenantToday >= max(1, (int) ($replies['tenant_daily_cap'] ?? 100))) {
            return $this->replies->handoff($tenant, $prospect, $inbound, 'tenant_daily_cap');
        }

        $transcript = ConversationMessage::where('conversation_id', $inbound->conversation_id)
            ->where('id', '!=', $inbound->id)
            ->whereIn('status', ['sent', 'received', 'approved'])
            ->orderByDesc('created_at')->limit(12)->get()->reverse()->values()->all();

        $built = $this->prompts->build($tenant, $prospect, $transcript, $inbound);
        $facts = $this->prompts->facts($tenant, $prospect);

        try {
            $completion = $this->ask($tenant, $built['prompt'], $built['system']);
            $this->recordCost($tenantId, $prospect, $completion);
            $checked = $this->filter->check((string) $completion['text'], $facts);

            // Malformed JSON is a formatting miss, not a judgement call: ask once
            // more, colder and blunter. Content refusals are never retried — a
            // second try at an unverifiable figure just invents a different one.
            if (($checked['reason'] ?? null) === 'invalid_json') {
                $completion = $this->ask($tenant, $built['prompt'].self::REPAIR_SUFFIX, $built['system'], 0.0);
                $this->recordCost($tenantId, $prospect, $completion);
                $checked = $this->filter->check((string) $completion['text'], $facts);
                $checked['repaired'] = $checked['ok'];
            }
        } catch (\Throwable $e) {
            Log::warning('recruiter bot inference failed', ['prospect_id' => $prospect->id, 'error' => $e->getMessage()]);

            return $this->replies->handoff($tenant, $prospect, $inbound, 'llm_error');
        }

        if (! $checked['ok'] || $checked['action'] === 'handoff') {
            return $this->replies->handoff($tenant, $prospect, $inbound, (string) ($checked['reason'] ?? 'model_requested'), [
                'model_reply' => mb_substr((string) $checked['reply'], 0, 1000),
            ]);
        }

        $this->bumpCounter($prospect, $today, $threadToday);

        $draft = ConversationMessage::create([
            'tenant_id' => $tenantId,
            'conversation_id' => $inbound->conversation_id,
            'direction' => 'out',
            'body' => $checked['reply'],
            'status' => 'draft',
            'sent_by' => self::SENT_BY,
            'draft_action' => $checked['action'],
            'draft_meta' => [
                'inbound_message_id' => $inbound->id,
                'extracted' => $checked['extracted'],
                'confidence' => $checked['confidence'],
                'model' => $completion['model'],
                'prompt_version' => RecruiterPromptBuilder::VERSION,
                'cost' => $completion['cost'],
                'mode' => $mode,
                'repaired' => (bool) ($checked['repaired'] ?? false),
            ],
        ]);

        if ($mode === 'auto') {
            $delivered = $this->replies->deliver($tenant, $draft);

            return $delivered['success']
                ? ['outcome' => 'sent', 'message_id' => (string) $draft->id]
                : ['outcome' => 'failed', 'reason' => (string) ($delivered['error'] ?? 'send_failed'), 'message_id' => (string) $draft->id];
        }

        $approval = $this->replies->requestApproval($tenant, $prospect, $draft, $inbound);

        return ['outcome' => 'drafted', 'message_id' => (string) $draft->id, 'approval_id' => (string) ($approval['id'] ?? '')];
    }

    /**
     * One inference-plane call with the draft settings.
     *
     * @return array{text: string, model: string, tokens_used: int, cost: float, provider: string}
     */
    private function ask(Tenant $tenant, string $prompt, string $system, float $temperature = 0.3): array
    {
        return $this->inference->generate(
            $prompt, $system, (string) ($tenant->plan ?: 'starter'), 0.05, 600, false, $temperature,
        );
    }

    private function bumpCounter(PartnerProspect $prospect, string $today, int $threadToday): void
    {
        PartnerProspect::whereKey($prospect->id)->update([
            'bot_replies_today' => $threadToday + 1,
            'bot_replies_day' => $today,
        ]);
    }

    /** @param array<string, mixed> $completion */
    private function recordCost(string $tenantId, PartnerProspect $prospect, array $completion): void
    {
        try {
            $this->costs->recordUsage($tenantId, 'outreach_reply', (float) ($completion['cost'] ?? 0.0), [
                'model' => $completion['model'] ?? null,
                'tokens' => $completion['tokens_used'] ?? null,
                'prospect_id' => $prospect->id,
            ]);
        } catch (\Throwable $e) {
            Log::debug('recruiter bot cost record skipped', ['error' => $e->getMessage()]);
        }
    }
}
