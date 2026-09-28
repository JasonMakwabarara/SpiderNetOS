<?php

declare(strict_types=1);

namespace Tests\Feature\Inference;

use App\Services\Inference\InferencePlaneClient;
use App\Services\Inference\InferenceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Which failures are an outage and which are the caller's own fault.
 *
 * An evaluator reports the first as missing evidence and the second as an
 * error, and the two need different fixes — so the client has to say which
 * one happened rather than throw one RuntimeException for everything.
 */
class InferencePlaneClientTest extends TestCase
{
    /** @return array<string, array{0: \Closure, 1: bool}> */
    public static function failures(): array
    {
        return [
            'connection refused' => [fn () => throw new ConnectionException('Connection refused'), true],
            '503 overloaded' => [fn () => Http::response('busy', 503), true],
            '500 crashed' => [fn () => Http::response('boom', 500), true],
            '429 rate limited' => [fn () => Http::response('slow down', 429), true],
            '408 timed out' => [fn () => Http::response('timeout', 408), true],
            'empty completion' => [fn () => Http::response(['text' => '  ', 'model' => 'm', 'provider' => 'p']), true],
            '400 bad request' => [fn () => Http::response('bad', 400), false],
            '401 bad token' => [fn () => Http::response('no', 401), false],
            '404 wrong route' => [fn () => Http::response('missing', 404), false],
            '422 schema mismatch' => [fn () => Http::response('invalid', 422), false],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_failure_says_whether_the_plane_was_unavailable(\Closure $response, bool $unavailable): void
    {
        Http::fake(['*/generate' => $response]);

        try {
            app(InferencePlaneClient::class)->generate('prompt');
            $this->fail('the call should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($unavailable, $e instanceof InferenceUnavailableException, $e::class.': '.$e->getMessage());
        }
    }
}
