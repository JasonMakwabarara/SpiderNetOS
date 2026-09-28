<?php

declare(strict_types=1);

/*
 * One competitor in an approval race (tests/Feature/Agents/ApprovalRaceTest).
 *
 * A separate PHP process with its own database session, so its transaction is
 * genuinely independent of the test's and of the other competitor's — which
 * two calls on one connection in one process can never be. It boots the
 * application the way a request does, applies the configuration the test runs
 * under (read from stdin, with the job), makes exactly one call, and prints
 * the outcome after a sentinel so stray output cannot corrupt it.
 *
 *   mode http  call a route (POST unless `method` says otherwise) as a given
 *              user, through the full middleware stack
 *   mode hook  deliver an approval resource hook directly, as a second
 *              resolution path (the chain and the controller) would
 *
 *   fail_event (http only)  this competitor's decision reaches the event it
 *              names and fails there — but only after it has seen the other
 *              competitor blocked behind it. It first renames its own session
 *              (`signal`), so the test can tell that it is past its
 *              compare-and-set and holding the row, and only then starts the
 *              other competitor (`await_waiter`).
 */

use App\Models\Event;
use App\Models\User;
use App\Services\ApprovalEngine;
use App\Services\EventStore;
use App\Services\TenantKeyManager;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$job = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
foreach ($job['config'] as $key => $value) {
    config()->set($key, $value);
}

$failing = null;
if (isset($job['fail_event'])) {
    $failing = new class($app->make(TenantKeyManager::class), $job['fail_event']) extends EventStore
    {
        public bool $sawWaiter = false;

        /** @param array{type: string, signal: string, await_waiter: string} $spec */
        public function __construct(TenantKeyManager $keys, private readonly array $spec)
        {
            parent::__construct($keys);
        }

        public function append(
            string $tenantId,
            string $aggregateType,
            mixed $aggregateId,
            mixed $eventType = null,
            array $payload = [],
            array $metadata = [],
            ?int $expectedVersion = null,
        ): Event {
            if ($eventType !== $this->spec['type']) {
                return parent::append($tenantId, $aggregateType, $aggregateId, $eventType, $payload, $metadata, $expectedVersion);
            }

            DB::statement('set application_name = '.DB::getPdo()->quote($this->spec['signal']));
            $deadline = microtime(true) + 30;
            while (! $this->sawWaiter && microtime(true) < $deadline) {
                // Activity is snapshotted per transaction; clear it or the
                // first answer is the only one this loop ever sees.
                DB::select('select pg_stat_clear_snapshot()');
                $this->sawWaiter = (int) DB::selectOne(
                    "select count(*) as n from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'",
                    [$this->spec['await_waiter']],
                )->n > 0;
                if (! $this->sawWaiter) {
                    usleep(50_000);
                }
            }

            throw new RuntimeException('event store unavailable');
        }
    };
    $app->instance(EventStore::class, $failing);
}

try {
    if ($job['mode'] === 'http') {
        $app['auth']->guard('sanctum')->setUser(User::findOrFail($job['user_id']));
        $app['auth']->shouldUse('sanctum');

        $request = Request::create($job['uri'], (string) ($job['method'] ?? 'POST'), [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode($job['body'] ?? []));
        $response = $app->make(HttpKernel::class)->handle($request);

        $out = ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
    } else {
        $app->make(ApprovalEngine::class)->fireResourceHook(
            $job['resource_type'], $job['tenant_id'], $job['resource_id'], (bool) $job['granted'], (string) ($job['response'] ?? ''), (array) ($job['decision'] ?? []),
        );
        $out = ['status' => 'returned'];
    }
} catch (Throwable $e) {
    $out = ['status' => 'threw', 'error' => $e::class.': '.$e->getMessage()];
}

if ($failing !== null) {
    $out['saw_waiter'] = $failing->sawWaiter;
}

fwrite(STDOUT, "\n@@RESULT@@".json_encode($out));
