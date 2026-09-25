<?php

declare(strict_types=1);

namespace App\Services\Inference;

/**
 * The inference plane could not produce an output: unreachable, timed out,
 * overloaded (5xx, 408, 429), or returned an empty completion.
 *
 * Kept apart from the plain RuntimeException the client throws for any other
 * refusal (a 400, 401, 404 or 422 means the request, the token or the route
 * is wrong — an adapter or configuration fault, not an outage). An evaluator
 * reports the first as missing evidence and the second as its own error, and
 * the two need different fixes. Extends RuntimeException, so every existing
 * `catch (\RuntimeException)` and `catch (\Throwable)` still sees it.
 */
final class InferenceUnavailableException extends \RuntimeException {}
