<?php

declare(strict_types=1);

namespace Tests\Feature\Agents\Support;

use App\Services\Agents\RunContext;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolResult;

/**
 * A tool with a configurable name and risk for gateway tests
 * (e.g. new FakeTool('crm.update_stage', 'write')).
 */
final class FakeTool implements ToolContract
{
    /** @var list<array<string, mixed>> params of every execute() call */
    public array $calls = [];

    /** @var null|callable(RunContext, array): ToolResult */
    private $handler;

    public function __construct(
        private readonly string $name,
        private readonly string $risk = ToolContract::RISK_WRITE,
        private readonly ?string $connector = null,
        ?callable $handler = null,
    ) {
        $this->handler = $handler;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return "Fake {$this->risk} tool {$this->name} for tests.";
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['stage' => ['type' => 'string']], 'additionalProperties' => true];
    }

    public function risk(): string
    {
        return $this->risk;
    }

    public function requiresConnector(): ?string
    {
        return $this->connector;
    }

    public function execute(RunContext $ctx, array $params): ToolResult
    {
        $this->calls[] = $params;

        if ($this->handler !== null) {
            return ($this->handler)($ctx, $params);
        }

        return ToolResult::ok(['echo' => $params, 'tool' => $this->name]);
    }
}
