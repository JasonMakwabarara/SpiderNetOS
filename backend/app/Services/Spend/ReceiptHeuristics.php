<?php

declare(strict_types=1);

namespace App\Services\Spend;

/**
 * Deterministic, zero-cost receipt text parser. Used when the inference
 * plane is unavailable or over budget. Every confidence it emits is <= 0.4
 * so heuristic extractions always land in needs_review (review_threshold
 * defaults to 0.6) — a human confirms before anything downstream trusts it.
 */
class ReceiptHeuristics
{
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * @return array{fields: array, line_items: array, overall_confidence: float, method: string, model: null, cost_usd: float}
     */
    public function extract(string $text): array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', $text) ?: []),
            fn (string $line) => $line !== ''
        ));

        $fields = [];

        $merchant = $this->guessMerchant($lines);
        if ($merchant !== null) {
            $fields['merchant'] = ['value' => $merchant, 'confidence' => 0.3];
        }

        [$total, $totalConfidence] = $this->guessTotal($lines);
        if ($total !== null) {
            $fields['total'] = ['value' => $total, 'confidence' => $totalConfidence];
        }

        $tax = $this->guessTax($lines);
        if ($tax !== null) {
            $fields['tax'] = ['value' => $tax, 'confidence' => 0.3];
        }

        $date = $this->guessDate($text);
        if ($date !== null) {
            $fields['date'] = ['value' => $date, 'confidence' => 0.4];
        }

        $fields['currency'] = $this->guessCurrency($text);

        $confidences = array_map(fn (array $f) => (float) $f['confidence'], $fields);
        $overall = $confidences === []
            ? 0.1
            : min(0.4, round(array_sum($confidences) / count($confidences), 4));

        return [
            'fields' => $fields,
            'line_items' => [],
            'overall_confidence' => $overall,
            'method' => 'heuristic',
            'model' => null,
            'cost_usd' => 0.0,
        ];
    }

    private function guessMerchant(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match_all('/\p{L}/u', $line) >= 3) {
                return mb_substr($line, 0, 120);
            }
        }

        return null;
    }

    /**
     * Largest currency-looking amount wins; amounts on lines mentioning
     * Total / Amount Due / Balance Due get a proximity boost.
     *
     * @return array{0: ?string, 1: float}
     */
    private function guessTotal(array $lines): array
    {
        $candidates = [];

        foreach ($lines as $line) {
            if (! preg_match_all('/(?:USD|GBP|EUR|[$\x{00A3}\x{20AC}])?\s*([0-9][0-9,]*\.[0-9]{2})\b/u', $line, $matches)) {
                continue;
            }

            $boosted = (bool) preg_match('/\b(grand\s+total|total|amount\s+due|balance\s+due)\b/i', $line);

            foreach ($matches[1] as $raw) {
                $amount = (float) str_replace(',', '', $raw);
                $candidates[] = ['amount' => $amount, 'boosted' => $boosted];
            }
        }

        if ($candidates === []) {
            return [null, 0.0];
        }

        $boostedOnly = array_filter($candidates, fn (array $c) => $c['boosted']);
        $pool = $boostedOnly !== [] ? $boostedOnly : $candidates;

        usort($pool, fn (array $a, array $b) => $b['amount'] <=> $a['amount']);
        $winner = array_values($pool)[0];

        return [number_format($winner['amount'], 2, '.', ''), $winner['boosted'] ? 0.4 : 0.3];
    }

    private function guessTax(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (! preg_match('/\b(tax|vat|gst)\b/i', $line)) {
                continue;
            }
            if (preg_match('/([0-9][0-9,]*\.[0-9]{2})\b/', $line, $m)) {
                return number_format((float) str_replace(',', '', $m[1]), 2, '.', '');
            }
        }

        return null;
    }

    private function guessDate(string $text): ?string
    {
        // ISO: 2026-01-15
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $m)) {
            return $this->validDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // Slashed or dotted: 01/15/2026, 15/01/2026, 15.01.2026
        if (preg_match('/\b(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})\b/', $text, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            $year = (int) $m[3];

            // Assume m/d/Y; flip when the first component cannot be a month.
            [$month, $day] = $a > 12 ? [$b, $a] : [$a, $b];

            return $this->validDate($year, $month, $day);
        }

        // Month-name first: Jan 15, 2026 / January 15 2026
        if (preg_match('/\b([A-Za-z]{3,9})\.?\s+(\d{1,2}),?\s+(\d{4})\b/', $text, $m)) {
            $month = self::MONTHS[strtolower(substr($m[1], 0, 3))] ?? null;
            if ($month !== null) {
                return $this->validDate((int) $m[3], $month, (int) $m[2]);
            }
        }

        // Day first: 15 Jan 2026
        if (preg_match('/\b(\d{1,2})\s+([A-Za-z]{3,9})\.?\s+(\d{4})\b/', $text, $m)) {
            $month = self::MONTHS[strtolower(substr($m[2], 0, 3))] ?? null;
            if ($month !== null) {
                return $this->validDate((int) $m[3], $month, (int) $m[1]);
            }
        }

        return null;
    }

    private function validDate(int $year, int $month, int $day): ?string
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * @return array{value: string, confidence: float}
     */
    private function guessCurrency(string $text): array
    {
        foreach (['USD' => '$', 'GBP' => "\u{00A3}", 'EUR' => "\u{20AC}"] as $code => $symbol) {
            if (str_contains($text, $symbol) || preg_match('/\b'.$code.'\b/', $text)) {
                return ['value' => $code, 'confidence' => 0.3];
            }
        }

        return ['value' => 'USD', 'confidence' => 0.2];
    }
}
