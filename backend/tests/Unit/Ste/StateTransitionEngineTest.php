<?php

namespace Tests\Unit\Ste;

use App\Services\StateTransitionEngine;
use PHPUnit\Framework\TestCase;

/**
 * Pure-math unit tests for StateTransitionEngine.
 *
 * These tests exercise buildMatrix() and computeDropoffs() without hitting
 * the database. They run on any driver (SQLite, Postgres, CI offline).
 */
class StateTransitionEngineTest extends TestCase
{
    private StateTransitionEngine $ste;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ste = new StateTransitionEngine;
    }

    // ------------------------------------------------------------------
    // buildMatrix
    // ------------------------------------------------------------------

    public function test_empty_rows_produce_empty_matrix(): void
    {
        $this->assertSame([], $this->ste->buildMatrix([]));
    }

    public function test_single_row_produces_probability_1(): void
    {
        $rows = [$this->row('a', 'b', 10)];
        $matrix = $this->ste->buildMatrix($rows);

        $this->assertArrayHasKey('a', $matrix);
        $this->assertArrayHasKey('b', $matrix['a']);
        // Damped: 0.85 * (10/10) + 0.15 * (1/1) = 1.0, then normalised = 1.0
        $this->assertEqualsWithDelta(1.0, $matrix['a']['b'], 1e-5);
    }

    public function test_two_equal_count_rows_produce_equal_probabilities(): void
    {
        $rows = [$this->row('a', 'b', 50), $this->row('a', 'c', 50)];
        $matrix = $this->ste->buildMatrix($rows);

        $pb = $matrix['a']['b'];
        $pc = $matrix['a']['c'];

        $this->assertEqualsWithDelta($pb, $pc, 1e-5, 'Equal counts must produce equal P');
        $this->assertEqualsWithDelta(1.0, $pb + $pc, 1e-5, 'Row must sum to 1.0');
    }

    public function test_row_probabilities_sum_to_one(): void
    {
        $rows = [
            $this->row('x', 'y', 20),
            $this->row('x', 'z', 30),
            $this->row('x', 'w', 50),
        ];
        $matrix = $this->ste->buildMatrix($rows);

        $sum = array_sum($matrix['x']);
        $this->assertEqualsWithDelta(1.0, $sum, 1e-5, 'All outgoing probabilities must sum to 1');
    }

    public function test_higher_count_produces_higher_probability(): void
    {
        $rows = [$this->row('s', 'completed', 80), $this->row('s', 'abandoned', 20)];
        $matrix = $this->ste->buildMatrix($rows);

        $this->assertGreaterThan($matrix['s']['abandoned'], $matrix['s']['completed']);
    }

    public function test_damping_prevents_zero_probability_for_minority_successor(): void
    {
        // Even with 1 observation vs 999, damping should keep P > 0
        $rows = [$this->row('a', 'b', 999), $this->row('a', 'c', 1)];
        $matrix = $this->ste->buildMatrix($rows);

        $this->assertGreaterThan(0.0, $matrix['a']['c']);
    }

    // ------------------------------------------------------------------
    // computeDropoffs
    // ------------------------------------------------------------------

    public function test_compute_dropoffs_session_lifecycle(): void
    {
        // All transitions go to 'abandoned' → dropoff from 'active' must be 1.0
        $rows = [$this->row('active', 'abandoned', 100)];
        $matrix = $this->ste->buildMatrix($rows);

        $dropoffs = $this->ste->computeDropoffs($matrix, 'session_lifecycle');

        $this->assertArrayHasKey('active', $dropoffs);
        $this->assertEqualsWithDelta(1.0, $dropoffs['active'], 1e-5);
    }

    public function test_compute_dropoffs_returns_zero_for_safe_transition(): void
    {
        // All transitions go to 'completed' (not a drop-off state)
        $rows = [$this->row('active', 'completed', 100)];
        $matrix = $this->ste->buildMatrix($rows);

        $dropoffs = $this->ste->computeDropoffs($matrix, 'session_lifecycle');

        $this->assertArrayHasKey('active', $dropoffs);
        $this->assertEqualsWithDelta(0.0, $dropoffs['active'], 1e-5);
    }

    public function test_compute_dropoffs_tenant_lifecycle_uses_churned(): void
    {
        $rows = [$this->row('dunning', 'churned', 60), $this->row('dunning', 'active', 40)];
        $matrix = $this->ste->buildMatrix($rows);

        $dropoffs = $this->ste->computeDropoffs($matrix, 'tenant_lifecycle');

        $this->assertArrayHasKey('dunning', $dropoffs);
        $this->assertGreaterThan(0.0, $dropoffs['dunning']);
        $this->assertLessThan(1.0, $dropoffs['dunning']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function row(string $from, string $to, int $count): object
    {
        return (object) [
            'from_state' => $from,
            'to_state' => $to,
            'tags' => '{}',
            'count' => $count,
        ];
    }
}
