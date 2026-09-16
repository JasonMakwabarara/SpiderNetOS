<?php

declare(strict_types=1);

namespace App\Services\Tools;

final class ToolResult
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly bool $success,
        public readonly array $data = [],
        public readonly ?string $error = null,
        public readonly float $cost = 0.0,
    ) {}

    /** @param array<string, mixed> $data */
    public static function ok(array $data = [], float $cost = 0.0): self
    {
        return new self(true, $data, null, $cost);
    }

    public static function fail(string $error, array $data = []): self
    {
        return new self(false, $data, $error, 0.0);
    }

    /** @return array{success: bool, data?: array<string, mixed>, error?: string} */
    public function toArray(): array
    {
        return $this->success
            ? ['success' => true, 'data' => $this->data]
            : ['success' => false, 'error' => (string) $this->error] + ($this->data !== [] ? ['data' => $this->data] : []);
    }
}
