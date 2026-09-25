TASK: Draft one LinkedIn connection note and the DM sequence that follows an accept.

Segment: {{inputs.segment}}
Prospect: {{inputs.prospect_id}}
Research report: {{inputs.research_report_id}}
DMs after accept: {{inputs.steps}}
Owner notes: {{inputs.notes}}

Write under the name, headline and register of the person in `people/user.md`. Use only what
the BRAIN and FACTS blocks say about the business, the offer, the proof and the voice. When a
research report is present under notes/research, choose the angle from it and put the evidence
in `angle_why`. When it is not, choose the safest angle the segment supports and say so.

Connection note under 300 characters, no link, no ask beyond connecting. Then {{inputs.steps}}
DMs (day 1, day 4, day 9, optional day 16), one ask each, bodies under 1,200 characters, plain
text.

Under `next_steps`, propose at most two follow-on steps the owner did not ask for, each
`{id, label, does, skill, inputs}`, using only skills named on this card
(prospect-research-analysis, inbox-triage-reply-classifier, meeting-booking).

Respond with the JSON object only.
