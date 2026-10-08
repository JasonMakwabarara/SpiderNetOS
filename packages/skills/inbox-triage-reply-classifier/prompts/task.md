TASK: Classify the new inbound replies and draft what should go back.

Thread: {{inputs.thread_id}}
Channel: {{inputs.channel}}
Campaign: {{inputs.campaign}}
Sequence: {{inputs.sequence_id}}
Segment: {{inputs.segment}}
Since: {{inputs.since}}

When a thread id is given, classify that thread's newest inbound message only. When it is
empty, use `inbox.list_unread` and `crm.list_replies` for everything new since the timestamp
(or the last run), and classify each message.

For every message return `classification`, a one-line `reason`, the `buying_signal` if any,
and `draft_action`. Draft a reply only for warm and hot. For hot, call `calendar.propose_slots`
for two slots in the owner's time zone and put them in `slots`; the draft offers those two
slots and nothing else. Flag complaints, legal language, refunds, cancellations, pricing
negotiation, press, "where did you get my details" and distress: `draft_action: escalate`,
no draft. Opt-outs: `draft_action: unsubscribe`, no draft.

Use only figures, prices and links from the FACTS block and the offer file. Answer objections
by agreeing first, then a fact from the offer file, then one small ask.

Under `next_steps`, propose at most two follow-on steps the owner did not ask for, each
`{id, label, does, skill, inputs}`, using only skills named on this card
(meeting-booking, follow-up-drafting).

Respond with the JSON object only.
