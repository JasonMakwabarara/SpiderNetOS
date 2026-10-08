TASK: Draft one cold email sequence.

Campaign: {{inputs.campaign}}
Segment: {{inputs.segment}}
Channel: {{inputs.channel}}
Steps: {{inputs.steps}} (problem → proof → close)
Subject variants per step: {{inputs.variants}}
Research report: {{inputs.research_report_id}}
Owner notes: {{inputs.notes}}

Write for the segment above using only what the BRAIN and FACTS blocks say about the business,
the offer, the proof and the voice. Where a research report id is given, the report is in the
BRAIN block under notes/research; use its angle and personalisation lines. Where it is not,
leave `{{first_line}}` as the personalisation slot in step 1 only.

Steps: 1 = problem (day 0), 2 = proof (day 3 or 4), 3 = close (day 7 or 8). Two subject lines
per step. One CTA per step. Under 900 characters per body. Plain text.

Then, under `next_steps`, propose at most two follow-on steps the owner did not ask for, each
`{id, label, does, skill, inputs}` using only skills named on this card
(follow-up-drafting, inbox-triage-reply-classifier, meeting-booking).

Respond with the JSON object only.
