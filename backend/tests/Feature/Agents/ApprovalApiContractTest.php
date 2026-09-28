<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Support\CanonicalJson;

/**
 * The cockpit's approval page is tested against a fixture of the API — and
 * the one it had was written by hand, in a shape the backend never produced:
 * the draft under `context.artifact`, content as an object. Every cockpit
 * test passed while the page could not work against the real server.
 *
 * This fixture is produced by the backend instead: a real run, the real
 * `GET /api/approvals?status=pending` response, normalised only where values
 * vary from run to run — ids, version hashes and timestamps become stable
 * placeholders, and the `context` JSON is re-encoded canonically, because
 * Postgres returns `jsonb` with its keys reordered. If the API's shape
 * changes, this test fails until the fixture is regenerated, and the cockpit
 * test then runs against the new shape.
 *
 *   UPDATE_CONTRACT_FIXTURES=1 vendor/bin/phpunit --filter ApprovalApiContractTest
 */
class ApprovalApiContractTest extends AgentsTestCase
{
    private const FIXTURE = '../cockpit/tests/fixtures/contract/approvals_pending_agent_artifact.json';

    public function test_the_cockpit_fixture_is_the_real_pending_approval_response(): void
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());
        $this->startRun();

        $response = $this->api()->getJson('/api/approvals?status=pending')->assertOk()->json();
        $actual = (new ContractNormaliser)->normalise(['data' => $response['data']]);

        $path = base_path(self::FIXTURE);
        if (getenv('UPDATE_CONTRACT_FIXTURES')) {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }

        $this->assertFileExists($path, 'The cockpit contract fixture is missing; regenerate it with UPDATE_CONTRACT_FIXTURES=1.');
        $this->assertSame(
            json_decode((string) file_get_contents($path), true),
            $actual,
            'The approvals API no longer matches the fixture the cockpit is tested against. Regenerate it with UPDATE_CONTRACT_FIXTURES=1 and review what changed for the cockpit.',
        );
    }
}

/**
 * Replaces what varies between runs with stable placeholders, numbered in the
 * order they are first met after sorting object keys, so the same response
 * normalises the same way on SQLite and on Postgres.
 */
final class ContractNormaliser
{
    /** @var array<string, string> */
    private array $seen = [];

    public function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $key === 'context' && is_string($item) ? $this->context($item) : $this->normalise($item);
            }

            return $out;
        }
        if (! is_string($value)) {
            return $value;
        }
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $value)) {
            return $this->placeholder($value, 'sha256:version-');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $value)) {
            return '<timestamp>';
        }

        return preg_replace_callback(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            fn (array $m): string => $this->placeholder(strtolower($m[0]), 'uuid-'),
            $value,
        );
    }

    /** The API sends `context` as a JSON string; keep it one, canonically encoded. */
    private function context(string $json): string
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? CanonicalJson::encode($this->normalise($decoded)) : $json;
    }

    private function placeholder(string $value, string $prefix): string
    {
        return $this->seen[$prefix.$value] ??= $prefix.(count(array_filter(array_keys($this->seen), fn (string $k): bool => str_starts_with($k, $prefix))) + 1);
    }
}
