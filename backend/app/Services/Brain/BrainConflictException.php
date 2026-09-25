<?php

declare(strict_types=1);

namespace App\Services\Brain;

/**
 * Thrown by BrainStore::write() when a caller's `base_version` no longer
 * matches the head: someone else wrote the file first. Carries the current
 * head version so the API can answer 409 {current_version} and the client
 * can rebase.
 */
final class BrainConflictException extends \RuntimeException
{
    public function __construct(
        public readonly string $path,
        public readonly int $currentVersion,
        public readonly ?int $baseVersion = null,
    ) {
        parent::__construct(sprintf(
            'Brain file "%s" is at version %d; base_version %s is stale.',
            $path,
            $currentVersion,
            $baseVersion === null ? 'null' : (string) $baseVersion,
        ));
    }

    public function currentVersion(): int
    {
        return $this->currentVersion;
    }
}
