<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed event_type → state-edge mappings for the STE.
 *
 * Authored once; super_admin can later extend via a future /api/ste/mapping
 * endpoint (out of scope for Phase 1 per plan §12.8).
 *
 * Chains:
 *   - session_lifecycle : visitor → chat_started → flow_running → tool_calling
 *                          → approval_pending → completed | abandoned | errored
 *   - tenant_lifecycle  : trial → active → expanded → dunning → churned
 *                          (+ re-activation: churned → active)
 *
 * Unknown from_state (NULL) = "any prior state" — handled by the projector.
 */
class SteEventMappingSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // ---------------- session_lifecycle ----------------
            ['atlas.chat.started',            'session_lifecycle', null,                 'chat_started',      ['surface' => 'atlas']],
            ['flow.execution.started',        'session_lifecycle', 'chat_started',       'flow_running',      ['surface' => 'flow']],
            ['flow.execution.started',        'session_lifecycle', null,                 'flow_running',      ['surface' => 'flow']],
            ['tool.invocation.started',       'session_lifecycle', 'flow_running',       'tool_calling',      []],
            ['approval.requested',            'session_lifecycle', null,                 'approval_pending',  []],
            ['approval.approved',             'session_lifecycle', 'approval_pending',   'completed',         ['outcome' => 'approved']],
            ['approval.rejected',             'session_lifecycle', 'approval_pending',   'abandoned',         ['outcome' => 'rejected']],
            ['flow.execution.completed',      'session_lifecycle', null,                 'completed',         []],
            ['flow.execution.failed',         'session_lifecycle', null,                 'errored',           []],
            ['session.abandoned',             'session_lifecycle', null,                 'abandoned',         []],

            // ---------------- tenant_lifecycle ----------------
            ['tenant.created',                'tenant_lifecycle', null,                 'trial',             []],
            ['tenant.onboarding.started',     'tenant_lifecycle', 'trial',              'tenant.onboarding_active', []], // NEW: Phase 1
            ['tenant.onboarding.completed',   'tenant_lifecycle', 'tenant.onboarding_active', 'active',        []], // NEW: Phase 1
            ['tenant.activated',              'tenant_lifecycle', 'tenant.onboarding_active', 'active',        []], // MODIFIED: from 'trial' -> 'onboarding_active'
            ['tenant.plan.upgraded',          'tenant_lifecycle', 'active',             'expanded',          []],
            ['budget.limit.exceeded',         'tenant_lifecycle', null,                 'dunning',           []],
            ['tenant.suspended',              'tenant_lifecycle', null,                 'dunning',           []],
            ['tenant.churned',                'tenant_lifecycle', null,                 'churned',           []],
            ['tenant.reactivated',            'tenant_lifecycle', 'churned',            'active',            ['re_activation' => true]],

            // ---------------- process_ownership (business-systemization pack) ----------------
            // founder_owned → documented → delegated/automated → needs_attention → automated
            ['systemization.system.created',         'process_ownership', null,               'founder_owned',   []],
            ['systemization.sop.published',          'process_ownership', null,               'documented',      []],
            ['systemization.process.owner_assigned', 'process_ownership', null,               'delegated',       []],
            ['systemization.process.automated',      'process_ownership', null,               'automated',       []],
            ['systemization.process.escalated',      'process_ownership', 'automated',        'needs_attention', []],
            ['systemization.escalation.resolved',    'process_ownership', 'needs_attention',  'automated',       ['sop_revised' => true]],

            // ---------------- usage aggregation bridge (Blocker A integration) ----------------
            // usage.aggregate.persisted is a systemic signal, not a user state change,
            // so it's intentionally unmapped. It will live in ste_unmapped_events until
            // a super_admin explicitly adds a mapping.

            // ---------------- pack.sales-crm.lead_lifecycle ----------------
            // NOTE: current/live-state tracking (ste_session_states) is only wired
            // for session_lifecycle/tenant_lifecycle in StateTransitionProjection;
            // pack chains get ste_transitions Markov counts only. `leads.stage`
            // (see App\Models\Lead) is the source of truth for a lead's live state.
            ['lead.form.submitted',            'pack.sales-crm.lead_lifecycle', null,             'captured',       []],
            ['lead.imported',                  'pack.sales-crm.lead_lifecycle', null,             'captured',       []],
            ['lead.score.calculated',          'pack.sales-crm.lead_lifecycle', 'captured',        'qualified',      []],
            ['conversation.message.received',  'pack.sales-crm.lead_lifecycle', 'captured',        'engaged',        []],
            ['conversation.message.received',  'pack.sales-crm.lead_lifecycle', 'qualified',       'engaged',        []],
            ['conversation.message.received',  'pack.sales-crm.lead_lifecycle', 'recycled',        'engaged',        []],
            ['meeting.booked',                 'pack.sales-crm.lead_lifecycle', 'engaged',         'meeting_booked', []],
            ['proposal.sent',                  'pack.sales-crm.lead_lifecycle', 'meeting_booked',  'proposal',       []],
            ['proposal.sent',                  'pack.sales-crm.lead_lifecycle', 'engaged',         'proposal',       []],
            ['deal.won',                       'pack.sales-crm.lead_lifecycle', 'proposal',        'won',            []],
            ['deal.won',                       'pack.sales-crm.lead_lifecycle', 'meeting_booked',  'won',            []],
            ['deal.won',                       'pack.sales-crm.lead_lifecycle', 'engaged',         'won',            []],
            ['deal.lost',                      'pack.sales-crm.lead_lifecycle', 'captured',        'lost',           ['reason' => 'payload.loss_reason']],
            ['deal.lost',                      'pack.sales-crm.lead_lifecycle', 'qualified',       'lost',           ['reason' => 'payload.loss_reason']],
            ['deal.lost',                      'pack.sales-crm.lead_lifecycle', 'engaged',         'lost',           ['reason' => 'payload.loss_reason']],
            ['deal.lost',                      'pack.sales-crm.lead_lifecycle', 'meeting_booked',  'lost',           ['reason' => 'payload.loss_reason']],
            ['deal.lost',                      'pack.sales-crm.lead_lifecycle', 'proposal',        'lost',           ['reason' => 'payload.loss_reason']],
            ['lead.re_engaged',                'pack.sales-crm.lead_lifecycle', 'lost',            'recycled',       []],

            // ---------------- pack.sales-crm.funnel_setup ----------------
            // Discovery interview -> script draft -> owner approval -> go-live.
            // `funnel_setups.status` (see App\Services\FunnelSetupService) is
            // the source of truth; these mappings only feed ste_transitions.
            ['pack.sales-crm.funnel_setup.purchased',                'pack.sales-crm.funnel_setup', null,               'purchased',        []],
            ['pack.sales-crm.funnel_setup.interview.started',        'pack.sales-crm.funnel_setup', 'purchased',        'interviewing',      []],
            ['pack.sales-crm.funnel_setup.script.drafted',           'pack.sales-crm.funnel_setup', 'interviewing',     'script_drafted',    []],
            ['pack.sales-crm.funnel_setup.approval.requested',       'pack.sales-crm.funnel_setup', 'script_drafted',   'awaiting_approval', []],
            ['pack.sales-crm.funnel_setup.approval.granted',         'pack.sales-crm.funnel_setup', 'awaiting_approval', 'approved',          []],
            ['pack.sales-crm.funnel_setup.approval.rejected',        'pack.sales-crm.funnel_setup', 'awaiting_approval', 'rejected',          []],
            ['pack.sales-crm.funnel_setup.script.revision_requested', 'pack.sales-crm.funnel_setup', 'awaiting_approval', 'interviewing',      []],
            ['pack.sales-crm.funnel_setup.script.revision_requested', 'pack.sales-crm.funnel_setup', 'rejected',         'interviewing',      []],
            ['pack.sales-crm.funnel_setup.went_live',                'pack.sales-crm.funnel_setup', 'approved',         'live',              []],
            ['pack.sales-crm.funnel_setup.paused',                   'pack.sales-crm.funnel_setup', 'live',             'paused',            []],
            ['pack.sales-crm.funnel_setup.resumed',                  'pack.sales-crm.funnel_setup', 'paused',           'live',              []],
        ];

        $now = now();
        foreach ($rows as [$eventType, $chain, $fromState, $toState, $tags]) {
            DB::table('ste_event_mapping')->updateOrInsert(
                [
                    'event_type' => $eventType,
                    'chain' => $chain,
                    'from_state' => $fromState,
                    'to_state' => $toState,
                ],
                [
                    'extract_tags' => json_encode((object) $tags),
                    'enabled' => true,
                    'created_at' => $now,
                ],
            );
        }
    }
}
