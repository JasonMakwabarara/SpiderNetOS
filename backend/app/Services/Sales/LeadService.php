<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Lead;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;

/**
 * Lead lifecycle for the sales-crm pack (pack.sales-crm.lead_lifecycle).
 *
 * `leads.stage` is the source of truth for a lead's current state — the STE
 * projector only patches ste_session_states/ste_tenant_states for the
 * session_lifecycle/tenant_lifecycle chains (see
 * App\Services\Projections\StateTransitionProjection), so pack chains only
 * get Markov counts in ste_transitions, not a live per-lead state row.
 */
class LeadService
{
    /** @var array<string, string> Target stage => event_type emitted to reach it. */
    private const STAGE_EVENTS = [
        'qualified' => 'lead.score.calculated',
        'engaged' => 'conversation.message.received',
        'meeting_booked' => 'meeting.booked',
        'proposal' => 'proposal.sent',
        'won' => 'deal.won',
        'lost' => 'deal.lost',
        'recycled' => 'lead.re_engaged',
    ];

    public function __construct(
        private readonly EventStore $eventStore,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function create(string $tenantId, array $data): Lead
    {
        return DB::transaction(function () use ($tenantId, $data) {
            $source = $data['source'] ?? 'manual';

            $lead = Lead::create([
                'tenant_id' => $tenantId,
                'name' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'whatsapp_number' => $data['whatsapp_number'] ?? null,
                'source' => $source,
                'stage' => 'captured',
                'score' => $this->heuristicScore($data),
                // Consent must be explicit: opt-ins default to false so cold
                // captures (PublicLeadController landing-page forms, imports)
                // are never auto-subscribed. Callers with real consent pass
                // the flags through (e.g. a ticked opt-in checkbox).
                'consent' => [
                    'email_opt_in' => (bool) ($data['email_opt_in'] ?? false),
                    'whatsapp_opt_in' => (bool) ($data['whatsapp_opt_in'] ?? false),
                    'opted_out_at' => null,
                ],
                'custom' => $data['custom'] ?? [],
            ]);

            $this->eventStore->append(
                $tenantId,
                'lead',
                $lead->id,
                $source === 'import' ? 'lead.imported' : 'lead.form.submitted',
                [
                    'lead_id' => $lead->id,
                    'source' => $source,
                    'score' => $lead->score,
                ],
            );

            return $lead;
        });
    }

    /**
     * Transition a lead to a new stage, emitting the matching STE event.
     *
     * @param array<string, mixed> $context
     */
    public function transitionStage(Lead $lead, string $toStage, array $context = []): Lead
    {
        if (! in_array($toStage, Lead::STAGES, true)) {
            throw new \InvalidArgumentException("Unknown lead stage: {$toStage}");
        }

        $eventType = self::STAGE_EVENTS[$toStage] ?? null;
        if ($eventType === null) {
            throw new \InvalidArgumentException("No STE event mapped for stage: {$toStage}");
        }

        $fromStage = $lead->stage;

        $lead->update([
            'stage' => $toStage,
            'last_contacted_at' => in_array($toStage, ['engaged', 'meeting_booked', 'proposal'], true)
                ? now()
                : $lead->last_contacted_at,
        ]);

        $this->eventStore->append(
            $lead->tenant_id,
            'lead',
            $lead->id,
            $eventType,
            array_merge(['lead_id' => $lead->id, 'from_stage' => $fromStage, 'to_stage' => $toStage], $context),
        );

        return $lead->refresh();
    }

    /**
     * Deterministic starting score so the pipeline is usable before the crm
     * agent's score_lead tool (intelligence/tools/registry.py) has run.
     *
     * @param array<string, mixed> $data
     */
    private function heuristicScore(array $data): int
    {
        $score = 30;

        if (! empty($data['email'])) {
            $score += 15;
        }
        if (! empty($data['phone']) || ! empty($data['whatsapp_number'])) {
            $score += 15;
        }
        if (($data['source'] ?? null) === 'referral') {
            $score += 25;
        } elseif (($data['source'] ?? null) === 'demo_request') {
            $score += 20;
        }

        return min(100, $score);
    }
}
