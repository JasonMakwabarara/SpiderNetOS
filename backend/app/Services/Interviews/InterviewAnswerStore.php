<?php

declare(strict_types=1);

namespace App\Services\Interviews;

/**
 * Where an InterviewRunner keeps its answers. Every pack that interviews a
 * founder (sales-crm's funnel_setups, business-launch's business_launches)
 * has its own row and its own columns, so the runner never names a model.
 */
interface InterviewAnswerStore
{
    /**
     * Answers keyed by question id: {question, answer, answered_at, skipped?}.
     *
     * @return array<string, mixed>
     */
    public function answers(): array;

    /**
     * Persist the full answer map and the section the last answer belonged to.
     *
     * @param  array<string, mixed>  $answers
     */
    public function put(array $answers, ?string $sectionId): void;
}
