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
    /** The question Atlas asks when no next step can be established (plan D0 f). */
    public const USER_STEP_QUESTION = 'What is one more step we could take here? (or say skip)';

    public const ONE_STEP_RULE = <<<'RULE'
Standing rule — go one step further (dig deeper, ask one more question):
After the answer — never before or inside it — add at most two short lines:
NEXT: one concrete step the user did not ask for, taken verbatim from the <NEXT_STEP> block. Omit this line when the block is empty. Never invent a step.
ASK: exactly one question, taken verbatim from the <ONE_MORE_QUESTION> block, ending with "(or say skip)". Never ask a second question anywhere in the reply.
Omit both lines when the user said skip, later or stop; when the message is a slash command or a simple acknowledgement; when you are already asking a clarifying or confirming question; or when the reply is an error, a refusal or a hold.
NEXT and ASK never change a number, a fact or a decision in the answer.
RULE;

    /**
     * Base identity prompt, optionally extended with a BRAIN block (the
     * tenant's Knowledge brain, plan D2) and the "one step further" blocks
     * (plan D8): <NEXT_STEP>, <ONE_MORE_QUESTION> and the standing rule.
     * Both extras are appended only when passed, so existing callers keep
     * the plain prompt.
     *
     * @param  array<string, mixed>|null  $brain    {block: string} or key => value/array pairs
     * @param  array<string, mixed>|null  $oneStep  {question?: string, next_step?: {label, does, skill?}}
     */
    public function systemPrompt(?array $brain = null, ?array $oneStep = null): string
    {
        $prompt = $this->basePrompt();

        if ($brain !== null && $brain !== []) {
            $prompt .= "\n\n".$this->brainBlock($brain);
        }

        if ($oneStep !== null) {
            $prompt .= "\n\n".$this->oneStepBlocks($oneStep);
        }

        return $prompt;
    }

    /**
     * The <NEXT_STEP> and <ONE_MORE_QUESTION> blocks plus the standing rule.
     * An empty next step leaves the block empty; an empty question falls
     * back to asking the user for one more step (plan D0 f).
     *
     * @param  array<string, mixed>  $oneStep
     */
    public function oneStepBlocks(array $oneStep): string
    {
        $next = $oneStep['next_step'] ?? null;
        $nextLine = '';
        if (is_array($next)) {
            $label = trim((string) ($next['label'] ?? ''));
            $does = trim((string) ($next['does'] ?? ''));
            $nextLine = $label !== '' ? $label.($does !== '' && $does !== $label ? ' — '.$does : '') : $does;
        } elseif (is_string($next)) {
            $nextLine = trim($next);
        }

        $question = trim((string) ($oneStep['question'] ?? ''));
        if ($question === '') {
            $question = self::USER_STEP_QUESTION;
        } elseif (! str_contains(strtolower($question), 'or say skip')) {
            $question .= ' (or say skip)';
        }

        return "<NEXT_STEP>\n{$nextLine}\n</NEXT_STEP>\n<ONE_MORE_QUESTION>\n{$question}\n</ONE_MORE_QUESTION>\n\n".self::ONE_STEP_RULE;
    }

    /**
     * @param  array<string, mixed>  $brain
     */
    private function brainBlock(array $brain): string
    {
        if (isset($brain['block']) && is_string($brain['block'])) {
            $body = trim($brain['block']);
        } else {
            $lines = [];
            foreach ($brain as $key => $value) {
                $lines[] = is_scalar($value) || $value === null
                    ? "{$key}: ".(string) $value
                    : "{$key}: ".json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $body = implode("\n", $lines);
        }

        return "<BRAIN>\n{$body}\n</BRAIN>";
    }

    private function basePrompt(): string
    {
        return <<<PROMPT
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
