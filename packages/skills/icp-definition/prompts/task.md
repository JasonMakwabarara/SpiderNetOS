TASK: Write or refresh customers/icp.md from the owner's answers.

Mode: {{inputs.mode}}
Answers so far: {{inputs.answers}}
Evidence (drift_check): {{inputs.evidence}}

Start from the current `customers/icp.md` in the BRAIN block if one exists (keep the owner's
prose; extend it), and from the discovery interview's ideal_customer and disqualifiers answers
when the file is empty. Use the answers keyed best_customer, problem_solved, disqualifiers,
buying_triggers, segments. Where an answer is missing, keep that section short and name the
one question that would fill it in `read_back` — never invent a customer or a segment.

"Who we sell to" describes the best customer as a person and a business in at least a hundred
characters. "Who we do not sell to" lists disqualifiers plainly. "Buying triggers" lists the
events that make now the moment. `segments` names one to three slices with firmographics that
a campaign or a research run can be scoped to; the first is the one to start with.

`read_back` quotes the owner's own words back in one or two lines before saving.

Under `next_steps`, propose at most two follow-on steps, each `{id, label, does, skill, inputs}`,
using only skills named on this card (prospect-research-analysis, brand-voice-keeper).

Respond with the JSON object only.
