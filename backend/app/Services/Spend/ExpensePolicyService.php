<?php

declare(strict_types=1);

namespace App\Services\Spend;

use App\Models\ExpenseCategory;
use App\Models\ExpensePolicy;
use App\Models\ExpenseReport;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Advisory expense-policy evaluation. Policies never block a submission —
 * they annotate line items with policy_flags and roll a violation count up
 * onto the report so approvers (and the UI) can see exactly what to probe.
 */
class ExpensePolicyService
{
    /**
     * Enabled policies that apply to this submitter: tenant-wide policies
     * plus role-scoped policies matching the submitter's role.
     *
     * @return Collection<int, ExpensePolicy>
     */
    public function applicablePolicies(string $tenantId, User $user): Collection
    {
        return ExpensePolicy::forTenant($tenantId)
            ->enabled()
            ->get()
            ->filter(fn (ExpensePolicy $policy) => $policy->appliesTo($user))
            ->values();
    }

    /**
     * Evaluate every item on the report. Writes policy_flags to each item,
     * sets report.policy_violation_count (total flags), and returns the
     * violation list.
     *
     * Flags:
     *  - over_category_limit  item amount > category per_expense_limit
     *  - missing_receipt      amount above the category's requires_receipt_over
     *                         or any applicable policy's receipt_required_over,
     *                         and the item has no receipt
     *  - category_not_allowed an applicable policy defines allowed_categories
     *                         and the item's category slug is not in it
     *
     * @return array<int, array{item_id: string, description: string, flags: array<int, string>}>
     */
    public function evaluate(ExpenseReport $report): array
    {
        $report->loadMissing(['items.category', 'user']);

        $policies = $report->user
            ? $this->applicablePolicies($report->tenant_id, $report->user)
            : collect();

        $violations = [];
        $flagCount = 0;

        foreach ($report->items as $item) {
            $flags = $this->evaluateItemFlags($item->amount, $item->has_receipt, $item->category, $policies);

            $item->policy_flags = $flags;
            $item->save();

            if ($flags !== []) {
                $flagCount += count($flags);
                $violations[] = [
                    'item_id' => $item->id,
                    'description' => $item->description,
                    'flags' => $flags,
                ];
            }
        }

        $report->policy_violation_count = $flagCount;
        $report->save();

        return $violations;
    }

    /**
     * Pure flag computation for a single item — shared by evaluate() and
     * directly unit-testable.
     *
     * @param  Collection<int, ExpensePolicy>  $policies
     * @return array<int, string>
     */
    public function evaluateItemFlags(
        string|float|null $amount,
        bool $hasReceipt,
        ?ExpenseCategory $category,
        Collection $policies,
    ): array {
        $flags = [];
        $amount = (string) ($amount ?? '0');

        if ($category?->per_expense_limit !== null
            && bccomp($amount, (string) $category->per_expense_limit, 4) > 0) {
            $flags[] = 'over_category_limit';
        }

        if (! $hasReceipt && $this->receiptRequired($amount, $category, $policies)) {
            $flags[] = 'missing_receipt';
        }

        foreach ($policies as $policy) {
            $allowed = $policy->allowed_categories;
            if (! empty($allowed) && ! in_array($category?->slug, $allowed, true)) {
                $flags[] = 'category_not_allowed';
                break;
            }
        }

        return $flags;
    }

    /** @param Collection<int, ExpensePolicy> $policies */
    private function receiptRequired(string $amount, ?ExpenseCategory $category, Collection $policies): bool
    {
        if ($category?->requires_receipt_over !== null
            && bccomp($amount, (string) $category->requires_receipt_over, 4) > 0) {
            return true;
        }

        foreach ($policies as $policy) {
            if ($policy->receipt_required_over !== null
                && bccomp($amount, (string) $policy->receipt_required_over, 4) > 0) {
                return true;
            }
        }

        return false;
    }
}
