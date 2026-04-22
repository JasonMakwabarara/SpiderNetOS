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

            // ---------------- usage aggregation bridge (Blocker A integration) ----------------
            // usage.aggregate.persisted is a systemic signal, not a user state change,
            // so it's intentionally unmapped. It will live in ste_unmapped_events until
            // a super_admin explicitly adds a mapping.
        ];

        $now = now();
        foreach ($rows as [$eventType, $chain, $fromState, $toState, $tags]) {
            DB::table('ste_event_mapping')->updateOrInsert(
                [
                    'event_type'  => $eventType,
                    'chain'       => $chain,
                    'from_state'  => $fromState,
                    'to_state'    => $toState,
                ],
                [
                    'extract_tags' => json_encode((object) $tags),
                    'enabled'      => true,
                    'created_at'   => $now,
                ],
            );
        }
    }
}
