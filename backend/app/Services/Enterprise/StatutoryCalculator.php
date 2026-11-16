<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Exceptions\DomainException;

/**
 * Applies a tenant-supplied statutory rule. Rates live in the rule row because they change.
 */
final class StatutoryCalculator
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array{paye: int, aids: int, nssa_employee: int, nssa_employer: int, nec: int, union: int, net: int}
     */
    public function deduct(int $grossCents, array $definition): array
    {
        $grossCents = max(0, $grossCents);
        $ceiling = Cents::from($definition['nssa_ceiling'] ?? 0);
        $nssaBase = $ceiling > 0 ? min($grossCents, $ceiling) : $grossCents;
        $nssaEmployee = Cents::applyRate($nssaBase, (float) ($definition['nssa_employee_rate'] ?? 0));
        $nssaEmployer = Cents::applyRate($nssaBase, (float) ($definition['nssa_employer_rate'] ?? 0));
        $taxable = max(0, $grossCents - $nssaEmployee);
        $paye = $this->bandTax($taxable, $definition['paye_bands'] ?? []);
        $aids = Cents::applyRate($paye, (float) ($definition['aids_levy_rate'] ?? 0));
        $nec = Cents::applyRate($grossCents, (float) ($definition['nec_rate'] ?? 0));
        $union = Cents::from($definition['union_dues'] ?? 0);
        $deductions = $nssaEmployee + $paye + $aids + $nec + $union;
        if ($deductions > $grossCents) {
            throw new DomainException('Statutory deductions cannot exceed gross pay.');
        }

        return [
            'paye' => $paye,
            'aids' => $aids,
            'nssa_employee' => $nssaEmployee,
            'nssa_employer' => $nssaEmployer,
            'nec' => $nec,
            'union' => $union,
            'net' => $grossCents - $deductions,
        ];
    }

    /**
     * @param  list<array{up_to: mixed, rate: mixed}>  $bands
     */
    private function bandTax(int $taxableCents, array $bands): int
    {
        $tax = 0;
        $previous = 0;
        foreach ($bands as $band) {
            $rate = (float) ($band['rate'] ?? 0);
            $cap = $band['up_to'] === null || $band['up_to'] === ''
                ? $taxableCents
                : Cents::from($band['up_to']);
            $slice = min($taxableCents, $cap) - $previous;
            if ($slice > 0) {
                $tax += Cents::applyRate($slice, $rate);
            }
            $previous = $cap;
            if ($taxableCents <= $cap) {
                break;
            }
        }

        return $tax;
    }
}
