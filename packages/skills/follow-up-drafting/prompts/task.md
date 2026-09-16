TASK: Draft the follow-ups that are due.

Thread: {{inputs.thread_id}}
Campaign: {{inputs.campaign}}
Segment: {{inputs.segment}}
Sequence: {{inputs.sequence_id}}
Touch: {{inputs.touch}}
Max drafts: {{inputs.max_drafts}}

When a thread id is given, draft the next touch for that thread only. When it is empty, use
`crm.list_replies` to find threads with no inbound reply past the cadence in
`processes/follow-ups.md`, skip anything triage marked cold, opt-out, bounce or flagged, and
draft one follow-up per thread up to the maximum.

Each follow-up carries one new angle: touch 2 = a case example from the offer file, touch 3 =
an insight or a proven change in their world, the last touch = a close-out marked
`is_close_out: true`. Bodies under 700 characters, plain text, one ask, no "just following up".
Use only figures, proof and links from the FACTS block and the offer file.

Under `next_steps`, propose at most two follow-on steps the owner did not ask for, each
`{id, label, does, skill, inputs}`, using only skills named on this card
(prospect-research-analysis, inbox-triage-reply-classifier, meeting-booking).

Respond with the JSON object only.
