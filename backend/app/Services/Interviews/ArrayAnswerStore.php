<?php

declare(strict_types=1);

namespace App\Services\Interviews;

/**
 * In-memory answers — for unit tests and for callers that only want the
 * runner's pack-file reading (loadPackFile / findQuestion) without a row.
 */
final class ArrayAnswerStore implements InterviewAnswerStore
{
    /** @param array<string, mixed> $answers */
    public function __construct(
        private array $answers = [],
        private ?string $sectionId = null,
    ) {}

    /** @return array<string, mixed> */
    public function answers(): array
    {
        return $this->answers;
    }

    /** @param array<string, mixed> $answers */
    public function put(array $answers, ?string $sectionId): void
    {
        $this->answers = $answers;
        $this->sectionId = $sectionId ?? $this->sectionId;
    }

    public function sectionId(): ?string
    {
        return $this->sectionId;
    }
}
