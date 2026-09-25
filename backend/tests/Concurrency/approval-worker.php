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
 *   mode http  POST a route as a given user, through the full middleware stack
 *   mode hook  deliver an approval resource hook directly, as a second
 *              resolution path (the chain and the controller) would
 */

use App\Models\User;
use App\Services\ApprovalEngine;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$job = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
foreach ($job['config'] as $key => $value) {
    config()->set($key, $value);
}

try {
    if ($job['mode'] === 'http') {
        $app['auth']->guard('sanctum')->setUser(User::findOrFail($job['user_id']));
        $app['auth']->shouldUse('sanctum');

        $request = Request::create($job['uri'], 'POST', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode($job['body'] ?? []));
        $response = $app->make(HttpKernel::class)->handle($request);

        $out = ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
    } else {
        $app->make(ApprovalEngine::class)->fireResourceHook(
            $job['resource_type'], $job['tenant_id'], $job['resource_id'], (bool) $job['granted'], (string) ($job['response'] ?? ''),
        );
        $out = ['status' => 'returned'];
    }
} catch (Throwable $e) {
    $out = ['status' => 'threw', 'error' => $e::class.': '.$e->getMessage()];
}

fwrite(STDOUT, "\n@@RESULT@@".json_encode($out));
