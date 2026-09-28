TASK: Propose two slots for a first call and draft the message that offers them.

Thread: {{inputs.thread_id}}
Contact: {{inputs.contact}}
Meeting type: {{inputs.meeting_type}}
Prospect time zone: {{inputs.prospect_timezone}}
Look ahead: {{inputs.window_days}} days

Read the thread (the newest inbound message is the hot reply). Take the meeting length,
meeting types, hours and booking link from `people/user.md` in the BRAIN block. Call
`calendar.propose_slots` for two slots inside those hours within the window, in the prospect's
time zone (default to the owner's). Label each slot for a human.

Write one message: acknowledge what they asked in one line, say what the call is for in the
offer's plain words, offer the two slots, stop. Add a one-line `confirmation_line` for after
they accept. Set `booking_link` only if the user file provides one.

If the calendar returns no usable slot, return an empty `slots` array and make `message` a
one-line question to the owner about which hours to open.

Under `next_steps`, propose at most one follow-on step, `{id, label, does, skill, inputs}`,
using only skills named on this card (follow-up-drafting, prospect-research-analysis).

Respond with the JSON object only.
