<?php

declare(strict_types=1);

namespace App\Services\Interviews;

use Illuminate\Database\Eloquent\Model;

/**
 * An InterviewAnswerStore backed by one Eloquent row: a json answers column
 * and (optionally) a column that remembers which section the founder is in.
 *
 *   new ModelAnswerStore($funnelSetup)                          // sales-crm
 *   new ModelAnswerStore($launch, 'interview_answers', null)    // business-launch
 */
final class ModelAnswerStore implements InterviewAnswerStore
{
    public function __construct(
        private readonly Model $model,
        private readonly string $answersColumn = 'interview_answers',
        private readonly ?string $sectionColumn = 'current_section',
    ) {}

    /** @return array<string, mixed> */
    public function answers(): array
    {
        return (array) ($this->model->{$this->answersColumn} ?? []);
    }

    /** @param array<string, mixed> $answers */
    public function put(array $answers, ?string $sectionId): void
    {
        $attributes = [$this->answersColumn => $answers];
        if ($this->sectionColumn !== null && $sectionId !== null) {
            $attributes[$this->sectionColumn] = $sectionId;
        }

        $this->model->update($attributes);
    }

    public function model(): Model
    {
        return $this->model;
    }
}
