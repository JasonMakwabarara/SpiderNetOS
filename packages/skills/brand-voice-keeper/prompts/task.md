TASK: Write or refresh brand/voice.md from the owner's answers.

Mode: {{inputs.mode}}
Answers so far: {{inputs.answers}}
Sample text the owner pasted: {{inputs.sample_text}}

Start from the current `brand/voice.md` in the BRAIN block if one exists (keep the owner's
prose; extend it), and from the discovery interview's preferred tone when the file is empty.
Use the answers keyed tone_adjectives, admired_brand, never_say, sample_sentences. Where an
answer is missing, keep that section short and name the one question that would fill it in
`read_back` — do not invent a tone or a phrase.

Frontmatter `tone` is two to four adjectives in the owner's words. "Tone" explains how we
sound in two or three sentences with one example. "Do and don't" lists the off-limits words and
claims (including "Never say or offer" from people/user.md, quoted back) and the phrases that are
unmistakably ours. "Voice" is the owner's pasted sentences, verbatim.

`read_back` quotes the owner's own words back in one or two lines before saving.

Under `next_steps`, propose at most two follow-on steps, each `{id, label, does, skill, inputs}`,
using only skills named on this card (icp-definition, cold-email-drafting).

Respond with the JSON object only.
