<?php

/**
 * SpiderNet OS — attention budget (plan D8 #4).
 *
 * Every notification event type has a default urgency tier:
 *   interrupt — goes straight through (push when the user allows it)
 *   bundle    — parked by NotificationBundler and sent once at the user's
 *               bundle_time as one summary ("4 drafts, 1 question — about
 *               6 minutes")
 *   silent    — logged only
 *
 * Users override per event type and set their own quiet hours, bundle time
 * and timezone in users.preferences.notifications:
 *   { urgency: {run_blocked: 'interrupt'}, quiet_hours: {start: '22:00', end: '07:00'},
 *     bundle_time: '08:00', timezone: 'Africa/Nairobi' }
 * Interrupt-tier events raised inside quiet hours are bundled instead.
 */
return [

    'event_types' => [
        'approval_pending' => ['urgency' => 'interrupt', 'label' => 'Approval waiting', 'minutes' => 1.5, 'noun' => ['approval', 'approvals']],
        'budget_alert' => ['urgency' => 'interrupt', 'label' => 'Budget alert', 'minutes' => 1.0, 'noun' => ['budget alert', 'budget alerts']],
        'brief_ready' => ['urgency' => 'interrupt', 'label' => 'Needs-You Today is ready', 'minutes' => 3.0, 'noun' => ['brief', 'briefs']],
        'run_blocked' => ['urgency' => 'bundle', 'label' => 'An agent has a question', 'minutes' => 1.0, 'noun' => ['question', 'questions']],
        'artifact_pending' => ['urgency' => 'bundle', 'label' => 'Draft ready for review', 'minutes' => 1.5, 'noun' => ['draft', 'drafts']],
        'decision_due' => ['urgency' => 'bundle', 'label' => 'Decision due', 'minutes' => 2.0, 'noun' => ['decision', 'decisions']],
        'delegation_expired' => ['urgency' => 'interrupt', 'label' => 'Delegation expired', 'minutes' => 1.0, 'noun' => ['expired delegation', 'expired delegations']],
        'breaker_tripped' => ['urgency' => 'interrupt', 'label' => 'Circuit breaker tripped', 'minutes' => 1.0, 'noun' => ['breaker trip', 'breaker trips']],
    ],

    // Unknown/legacy event types (outreach_digest, spend_digest, bill_due_soon...)
    // keep today's behaviour: straight through.
    'default_urgency' => 'interrupt',

    'defaults' => [
        'timezone' => 'UTC',
        'quiet_hours' => ['start' => '22:00', 'end' => '07:00'],
        'bundle_time' => '08:00',
    ],

    // The summary's time estimate falls back to this per item.
    'minutes_per_item' => 1.5,

];
