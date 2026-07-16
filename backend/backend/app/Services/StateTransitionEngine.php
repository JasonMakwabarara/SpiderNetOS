<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * StateTransitionEngine (STE)
 *
 * Read-first analytics service projected over ste_transitions. All queries are
 * idempotent, stateless, and tenant-optional.
 *
 * Damping: matrix rows are blended as 0.85 * learned + 0.15 * uniform to avoid
 * feedback collapse (same PageRank trick documented in plan §12.5).
 */
class StateTransitionEngine
{
    public const DAMPING  = 0.85;

    /**
     * All absorbing states per chain — used by the MC simulator to stop walks.
     */
    public const TERMINAL_STATES_SESSION = ['completed', 'abandoned', 'errored'];
    public const TERMINAL_STATES_TENANT  = ['churned'];

    /**
     * "Drop-off" subset — negative exits only. Excludes successful completion.
     */
    public const DROPOFF_STATES_SESSION = ['abandoned', 'errored'];
    public const DROPOFF_STATES_TENANT  = ['churned'];

    /**
     * Returns the damped transition probability matrix for a chain.
     *
     * @return array<string, array<string, float>>  from → (to → p)
     */
    public function matrix(string $chain, ?string $tenantId = null): array
    {
        $rows = $this->fetchTransitions($chain, $tenantId);
        return $this->buildMatrix($rows);
    }

    /**
     * Conditional matrix P(to|from, tags). tagFilter is a map {tagKey: tagValue}
     * that must all be present in the transitions row's tags jsonb.
     */
    public function conditionalMatrix(string $chain, array $tagFilter, ?string $tenantId = null): array
    {
        $rows = $this->fetchTransitions($chain, $tenantId, $tagFilter);
        return $this->buildMatrix($rows);
    }

    /**
     * For each state, returns the damped probability of reaching a terminal
     * "drop-off" state (abandoned, errored for session; churned for tenant).
     *
     * @return array<string, float>  state → P(dropoff|state)
     */
    public function dropoffs(string $chain, ?string $tenantId = null): array
    {
        $matrix = $this->matrix($chain, $tenantId);
        return $this->computeDropoffs($matrix, $chain);
    }

    /**
     * Pure matrix → dropoffs computation. Exposed for tests.
     */
    public function computeDropoffs(array $matrix, string $chain): array
    {
        $dropoff = $chain === 'session_lifecycle'
            ? self::DROPOFF_STATES_SESSION
            : self::DROPOFF_STATES_TENANT;

        $result = [];
        foreach ($matrix as $from => $row) {
            $p = 0.0;
            foreach ($dropoff as $term) {
                $p += $row[$term] ?? 0.0;
            }
            $result[$from] = round($p, 6);
        }
        ksort($result);
        return $result;
    }

    /**
     * Returns tag combinations with the highest lift for reaching an activation
     * state (completed for sessions; active/expanded for tenants).
     *
     * Lift = P(target | from_state, tag) - P(target | from_state)
     *
     * @return list<array{tag_key: string, tag_value: string, from_state: string, lift: float, observations: int}>
     */
    public function winningTags(string $chain, string $metric = 'activation', ?string $tenantId = null, int $limit = 20): array
    {
        $target = $this->targetStateFor($chain, $metric);
        $baseMatrix = $this->matrix($chain, $tenantId);

        $rows = $this->fetchTransitions($chain, $tenantId);

        // Aggregate counts per (from, tag_key:tag_value)
        $conditional = [];  // from => tagKV => [target_count, total]
        foreach ($rows as $row) {
            $tags = $this->decodeJson($row->tags);
            if (empty($tags)) {
                continue;
            }
            foreach ($tags as $tagKey => $tagValue) {
                if ($tagValue === null) continue;
                $kv   = $tagKey . '=' . (is_scalar($tagValue) ? (string) $tagValue : json_encode($tagValue));
                $from = $row->from_state;

                $conditional[$from][$kv] ??= ['target' => 0, 'total' => 0];
                $conditional[$from][$kv]['total']  += (int) $row->count;
                if ($row->to_state === $target) {
                    $conditional[$from][$kv]['target'] += (int) $row->count;
                }
            }
        }

        $results = [];
        foreach ($conditional as $from => $tagMap) {
            $baseP = $baseMatrix[$from][$target] ?? 0.0;
            foreach ($tagMap as $kv => $stats) {
                if ($stats['total'] < 10) continue; // noise floor

                $condP = $stats['target'] / max(1, $stats['total']);
                $lift  = $condP - $baseP;

                [$tagKey, $tagValue] = explode('=', $kv, 2);
                $results[] = [
                    'tag_key'      => $tagKey,
                    'tag_value'    => $tagValue,
                    'from_state'   => $from,
                    'lift'         => round($lift, 6),
                    'observations' => (int) $stats['total'],
                ];
            }
        }

        usort($results, fn ($a, $b) => $b['lift'] <=> $a['lift']);
        return array_slice($results, 0, $limit);
    }

    /**
     * Lag in seconds between now() and the freshest transition row.
     * Used by the atlas.ste.lag alert rule.
     */
    public function lagSeconds(): int
    {
        $latest = DB::table('ste_transitions')->max('last_seen_at');
        if (!$latest) return 0;
        return max(0, now()->diffInSeconds(\Carbon\Carbon::parse($latest), false) * -1);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function fetchTransitions(string $chain, ?string $tenantId, array $tagFilter = []): array
    {
        $q = DB::table('ste_transitions')->where('chain', $chain);

        if ($tenantId) {
            $q->where('tenant_id', $tenantId);
        } else {
            // Cross-tenant view: include both tenant_id IS NULL and any tenant rows
            // (aggregate them). Caller who wants strictly cross-tenant can pass a
            // specific tenant. Here we aggregate all.
        }

        foreach ($tagFilter as $k => $v) {
            $q->whereRaw("tags @> ?::jsonb", [json_encode([$k => $v])]);
        }

        return $q->get(['chain', 'from_state', 'to_state', 'tags', 'count'])->all();
    }

    /**
     * Build the damped P(to|from) matrix from raw transition rows.
     *
     * Public for testability — accepts the same shape that fetchTransitions()
     * returns, so unit tests can exercise the pure math without mocking DB.
     */
    public function buildMatrix(array $rows): array
    {
        $sum  = [];   // from => total
        $cell = [];   // from => to => count

        foreach ($rows as $row) {
            $from = $row->from_state;
            $to   = $row->to_state;
            $c    = (int) $row->count;
            $sum[$from]       = ($sum[$from] ?? 0) + $c;
            $cell[$from][$to] = ($cell[$from][$to] ?? 0) + $c;
        }

        $matrix = [];
        foreach ($cell as $from => $toCounts) {
            $total     = $sum[$from] ?: 1;
            $uniformP  = 1.0 / max(1, count($toCounts));
            $matrix[$from] = [];
            foreach ($toCounts as $to => $c) {
                $learned = $c / $total;
                $damped  = self::DAMPING * $learned + (1 - self::DAMPING) * $uniformP;
                $matrix[$from][$to] = round($damped, 6);
            }

            // Normalise (damping slightly shifts mass; re-normalise to keep ∑ = 1)
            $rowSum = array_sum($matrix[$from]);
            if ($rowSum > 0) {
                foreach ($matrix[$from] as $to => $p) {
                    $matrix[$from][$to] = round($p / $rowSum, 6);
                }
            }

            ksort($matrix[$from]);
        }

        ksort($matrix);
        return $matrix;
    }

    private function targetStateFor(string $chain, string $metric): string
    {
        if ($chain === 'session_lifecycle') {
            return $metric === 'activation' ? 'completed' : 'completed';
        }
        return $metric === 'activation' ? 'active' : 'expanded';
    }

    private function decodeJson(mixed $v): array
    {
        if (is_array($v)) return $v;
        if (!is_string($v) || $v === '') return [];
        $d = json_decode($v, true);
        return is_array($d) ? $d : [];
    }
}
