<?php

declare(strict_types=1);

namespace App\Services\Agents\Exceptions;

/**
 * A review bundle does not hold together: a step names an artifact outside
 * it, from another run or of the wrong kind, a member is missing, or the
 * content no longer matches the version that was approved. Never applied,
 * never retried into success — it needs a person to look.
 */
final class BundleIntegrityException extends \RuntimeException {}
