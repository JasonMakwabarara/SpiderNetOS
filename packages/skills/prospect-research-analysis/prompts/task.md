TASK: Research one prospect and write the cited report.

Prospect: {{inputs.prospect}}
Segment: {{inputs.segment}}
Intended channel: {{inputs.channel}}
Depth: {{inputs.depth}}
Specific questions: {{inputs.questions}}

Check "Who we do not sell to" in the BRAIN block first. Then score fit 0–100 against
"Who we sell to" with one reason per line. Use `brain.search` and `notes/research` for
anything already known; use `crm.list_replies` when a lead id is given; use `sources.scan`
only when depth is `deep` and Research is enabled. Every angle carries evidence and a source
you read in this run. Mark uncertain findings uncertain.

Write two personalisation lines in the voice file's tone, each tied to one cited fact, with no
number unless the source has it.

Under `next_steps`, propose at most two follow-on steps the owner did not ask for, each
`{id, label, does, skill, inputs}`, using only skills named on this card
(cold-email-drafting, linkedin-outreach-specialist, icp-definition).

Respond with the JSON object only.
