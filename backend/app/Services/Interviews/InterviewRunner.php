<?php

declare(strict_types=1);

namespace App\Services\Interviews;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * Walks a feature pack's `interview/questions.yaml` — the shared half of
 * every pack interview, extracted from FunnelSetupService (plan D7,
 * cross-cutting prerequisites) so business-launch reuses it byte-for-byte
 * instead of copying it.
 *
 * It is parameterised by a pack id and an InterviewAnswerStore, and knows
 * nothing about funnels, launches, brains or approvals: the caller keeps its
 * own side effects (profile absorption, brain projection, stage commits).
 *
 * Question ids are unique across the whole file, so `answers` is keyed by id
 * alone; `<section id>.<question id>` is the *variable* stages.yaml requires
 * (see variableFor()).
 */
class InterviewRunner
{
    /** @var array<string, array<string, mixed>> relative path => parsed yaml */
    private array $packFiles = [];

    public function __construct(
        private readonly string $packId,
        private readonly InterviewAnswerStore $store,
    ) {}

    public function packId(): string
    {
        return $this->packId;
    }

    /**
     * The interview backbone. Always returns a `sections` key so callers can
     * iterate without checking.
     *
     * @return array<string, mixed>
     */
    public function loadInterviewQuestions(): array
    {
        return $this->loadPackFile('interview/questions.yaml') ?: ['sections' => []];
    }

    /**
     * Staged-pack storage path first, source tree fallback for local dev
     * before the pack has been staged via spidernet:pack-install.
     *
     * @return array<string, mixed>
     */
    public function loadPackFile(string $relativePath): array
    {
        if (array_key_exists($relativePath, $this->packFiles)) {
            return $this->packFiles[$relativePath];
        }

        $path = storage_path('app/feature-packs/'.$this->packId.'/'.$relativePath);

        if (! is_readable($path)) {
            $path = dirname(base_path()).'/packages/feature-packs/'.$this->packId.'/'.$relativePath;
        }

        if (! is_readable($path)) {
            return $this->packFiles[$relativePath] = [];
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (\Throwable $e) {
            Log::warning('InterviewRunner: unreadable pack file', [
                'pack_id' => $this->packId,
                'path' => $relativePath,
                'error' => $e->getMessage(),
            ]);

            return $this->packFiles[$relativePath] = [];
        }

        return $this->packFiles[$relativePath] = is_array($parsed) ? $parsed : [];
    }

    /**
     * @param  array<string, mixed>|null  $questions  pre-loaded backbone (defaults to this pack's)
     * @return array<string, mixed>|null
     */
    public function findQuestion(?array $questions, string $questionId): ?array
    {
        $questions ??= $this->loadInterviewQuestions();

        foreach ($questions['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                if (($question['id'] ?? null) === $questionId) {
                    return $question;
                }
            }
        }

        return null;
    }

    /** The section id a question belongs to. */
    public function sectionIdFor(string $questionId): ?string
    {
        foreach ($this->loadInterviewQuestions()['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                if (($question['id'] ?? null) === $questionId) {
                    return (string) ($section['id'] ?? '') ?: null;
                }
            }
        }

        return null;
    }

    /** `<section id>.<question id>` — the variable stages.yaml requires. */
    public function variableFor(string $questionId): ?string
    {
        $section = $this->sectionIdFor($questionId);

        return $section === null ? null : $section.'.'.$questionId;
    }

    /** Question id for a `<section>.<question>` variable, when it exists. */
    public function questionIdForVariable(string $variable): ?string
    {
        [$sectionId, $questionId] = array_pad(explode('.', $variable, 2), 2, null);
        if ($questionId === null) {
            return null;
        }

        foreach ($this->loadInterviewQuestions()['sections'] ?? [] as $section) {
            if ((string) ($section['id'] ?? '') !== $sectionId) {
                continue;
            }
            foreach ($section['questions'] ?? [] as $question) {
                if (($question['id'] ?? null) === $questionId) {
                    return $questionId;
                }
            }
        }

        return null;
    }

    /**
     * The next unanswered question in file order.
     *
     * @return array{done: bool, section?: array, question?: array, progress_pct: int}
     */
    public function nextQuestion(): array
    {
        $questions = $this->loadInterviewQuestions();
        $sections = $questions['sections'] ?? [];
        $answers = $this->answers();

        $totalQuestions = array_sum(array_map(fn ($s) => count($s['questions'] ?? []), $sections));
        $answeredCount = count($answers);

        foreach ($sections as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                if (! array_key_exists($question['id'], $answers)) {
                    return [
                        'done' => false,
                        'section' => ['id' => $section['id'], 'title' => $section['title'] ?? $section['id']],
                        'question' => $question,
                        'progress_pct' => $totalQuestions > 0 ? (int) round(100 * $answeredCount / $totalQuestions) : 0,
                    ];
                }
            }
        }

        return ['done' => true, 'progress_pct' => 100];
    }

    /**
     * Record one answer and return the stored entry. `$skipped` records the
     * question as asked-and-passed so the runner stops offering it.
     *
     * @return array{question: string, answer: string, answered_at: string, skipped?: bool}
     */
    public function recordAnswer(string $questionId, string $answer, bool $skipped = false): array
    {
        $questions = $this->loadInterviewQuestions();
        $questionDef = $this->findQuestion($questions, $questionId);

        $entry = [
            'question' => $questionDef['prompt'] ?? $questionId,
            'answer' => $answer,
            'answered_at' => now()->toIso8601String(),
        ];
        if ($skipped) {
            $entry['skipped'] = true;
        }

        $answers = $this->answers();
        $answers[$questionId] = $entry;

        $currentSection = null;
        foreach ($questions['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $q) {
                if (($q['id'] ?? null) === $questionId) {
                    $currentSection = (string) ($section['id'] ?? '');
                }
            }
        }

        $this->store->put($answers, $currentSection);

        return $entry;
    }

    /** @return array<string, mixed> */
    public function answers(): array
    {
        return $this->store->answers();
    }

    /** True when the question has been answered (a skip counts as answered). */
    public function isAnswered(string $questionId): bool
    {
        return array_key_exists($questionId, $this->answers());
    }

    /**
     * Answered variables as `<section>.<question>` => answer text. Skipped
     * questions are omitted: stages.yaml treats them as still missing.
     *
     * @return array<string, string>
     */
    public function answeredVariables(): array
    {
        $answers = $this->answers();
        $out = [];

        foreach ($this->loadInterviewQuestions()['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                $id = (string) ($question['id'] ?? '');
                $entry = $answers[$id] ?? null;
                if ($entry === null) {
                    continue;
                }
                $value = is_array($entry) ? (string) ($entry['answer'] ?? '') : (string) $entry;
                if ((is_array($entry) && ! empty($entry['skipped'])) || trim($value) === '') {
                    continue;
                }
                $out[$section['id'].'.'.$id] = $value;
            }
        }

        return $out;
    }
}
