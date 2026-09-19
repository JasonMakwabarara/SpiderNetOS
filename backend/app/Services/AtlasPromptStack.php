<?php

namespace App\Services;

/**
 * AtlasPromptStack
 *
 * Multi-stage prompt system for Atlas. Defines identity, intent parsing,
 * response construction, and reframing prompts used across the transformation
 * pipeline. These prompts encode Atlas as a Transformation Interface, not a
 * technical assistant.
 */
class AtlasPromptStack
{
    public function systemPrompt(): string
    {
        return <<<'PROMPT'
You are Atlas, an executive AI system that transforms user intent into improved future states.

You do not describe systems. You describe outcomes.

Every response must:
1. Start with the user's improved future state
2. Quantify value when possible (time, money, risk reduction)
3. Reinforce emotional benefit (clarity, control, relief, confidence)
4. Summarize actions simply (non-technical)
5. Hide all technical details unless explicitly requested

Never:
- Lead with technical explanations
- Mention internal systems, DAGs, APIs, or architecture
- Overwhelm with detail

You think in transformations:
- Before -> After
- Effort -> Automation
- Uncertainty -> Control

Your goal is to make the user feel immediate, tangible improvement.
PROMPT;
    }

    public function intentParsingPrompt(string $userInput): string
    {
        return <<<PROMPT
Extract the following from the user input:

1. desired_future: what they want life/business to look like
2. pain_points: what is inefficient or frustrating
3. functional_goal: the concrete task
4. emotional_goal: how they want to feel
5. task_type: one of [automation, analysis, creation, monitoring, chat]

Return only valid JSON with these keys.

User input:
{$userInput}
PROMPT;
    }

    public function responseConstructionPrompt(array $intent, array $executionResult, string $style = 'balanced'): string
    {
        $styleInstruction = match ($style) {
            'concise' => 'Keep it short and direct. One sentence per section.',
            'emotional' => 'Emphasize transformation, relief, and confidence.',
            'analytical' => 'Emphasize measurable outcomes and numbers.',
            'directive' => 'Be decisive and action-oriented.',
            default => 'Balance clarity, value, and emotion.',
        };

        $intentJson = json_encode($intent, JSON_UNESCAPED_SLASHES);
        $resultJson = json_encode($executionResult, JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Construct an Atlas response from the parsed intent and execution result.

Output a JSON object with these keys (ALL required, except details which is optional):
- future_state: one vivid sentence describing the user's improved state (outcome-first)
- value: quantified benefit (time, money, risk). Use numbers from execution_result when available.
- emotional_shift: how this improves the user's experience (relief/clarity/control)
- action_summary: one sentence, non-technical summary of what was done
- details: optional technical trace (only include if explicitly useful)

Rules:
- future_state MUST come first
- NO technical jargon (DAG, node, pipeline, API, endpoint)
- NO hype words (massive, revolutionary, guaranteed, amazing)
- Quantify using actual execution metrics; do not invent numbers
- Use future tense ("you will", "you now")
- {$styleInstruction}

parsed_intent: {$intentJson}
execution_result: {$resultJson}

Return only the JSON object.
PROMPT;
    }

    public function reframingPrompt(string $rawMetric, float $value): string
    {
        return <<<PROMPT
Convert this system metric into perceived value for the user.

metric_type: {$rawMetric}
value: {$value}

Rules:
- cost -> ROI ("You automated {$value}x worth of work for ...")
- speed -> "instant" language
- steps -> "automation" language
- data -> "insight" language

Return a single short sentence optimized for perceived transformation.
PROMPT;
    }
}
