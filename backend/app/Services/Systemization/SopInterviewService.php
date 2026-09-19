<?php

declare(strict_types=1);

namespace App\Services\Systemization;

/**
 * SOP clarification engine.
 *
 * An SOP is a recipe someone can follow on their first day without asking
 * questions. This service interviews instead of accepting a blank page:
 * it evaluates the collected answers, challenges vague or missing pieces,
 * and only signs off when the SOP is followable.
 */
class SopInterviewService
{
    /** Generic verbs that hide the actual work when used without detail. */
    private const VAGUE_VERBS = [
        'handle', 'manage', 'process', 'deal with', 'sort', 'sort out',
        'do the', 'upload', 'send', 'check', 'update', 'review', 'follow up',
    ];

    private const MIN_STEPS = 3;

    private const MIN_STEP_LENGTH = 20;

    /**
     * @param  array{title?: string, purpose?: string, trigger?: string, tools?: array, steps?: array, quality_criteria?: array}  $answers
     * @return array{complete: bool, questions: array<int, string>}
     */
    public function evaluate(array $answers): array
    {
        $questions = [];

        if (trim((string) ($answers['title'] ?? '')) === '') {
            $questions[] = 'What is the task called? Name it the way your team refers to it.';
        }

        if (mb_strlen(trim((string) ($answers['purpose'] ?? ''))) < 15) {
            $questions[] = 'Why does this task exist? What breaks or is lost if nobody does it?';
        }

        if (trim((string) ($answers['trigger'] ?? '')) === '') {
            $questions[] = 'When should someone start this task? Name the exact trigger (e.g. "when a lead books a call", "every Monday 9am").';
        }

        $tools = array_filter((array) ($answers['tools'] ?? []), fn ($t) => trim((string) $t) !== '');
        if ($tools === []) {
            $questions[] = 'Which tools, logins, or documents does this task need? List every one.';
        }

        $steps = array_values(array_filter(
            (array) ($answers['steps'] ?? []),
            fn ($s) => trim((string) $s) !== ''
        ));

        if (count($steps) < self::MIN_STEPS) {
            $questions[] = 'Break the task into at least '.self::MIN_STEPS.' concrete steps — imagine training someone on their first day.';
        } else {
            foreach ($steps as $i => $step) {
                if ($vagueQuestion = $this->challengeStep((string) $step, $i + 1)) {
                    $questions[] = $vagueQuestion;
                }
            }
        }

        $quality = array_filter((array) ($answers['quality_criteria'] ?? []), fn ($q) => trim((string) $q) !== '');
        if ($quality === []) {
            $questions[] = 'How do you know the task was done correctly? Give at least one measurable success criterion (e.g. "invoice sent within 15 minutes").';
        }

        return [
            'complete' => $questions === [],
            'questions' => array_values(array_unique($questions)),
        ];
    }

    /**
     * Returns a clarification question when a step is too vague to follow.
     */
    private function challengeStep(string $step, int $number): ?string
    {
        $trimmed = trim($step);
        $lower = mb_strtolower($trimmed);

        if (mb_strlen($trimmed) < self::MIN_STEP_LENGTH) {
            return "Step {$number} (\"{$trimmed}\") is too thin to follow. Where exactly, with which tool, and what does 'done' look like?";
        }

        foreach (self::VAGUE_VERBS as $verb) {
            // A vague verb is fine when the step goes on to specify details;
            // short vague-verb steps get challenged.
            if (str_starts_with($lower, $verb) && mb_strlen($trimmed) < 60) {
                return "Step {$number} starts with \"{$verb}\" but doesn't say how. Which system, which template, what happens if it fails?";
            }
        }

        return null;
    }
}
