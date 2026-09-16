<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Services\Tools\Brain\BrainReadTool;
use App\Services\Tools\Brain\BrainSearchTool;
use App\Services\Tools\Drafts\DraftsSaveSequenceTool;
use App\Services\Tools\Drafts\DraftsSaveTool;
use App\Services\Tools\Drafts\DraftsSubmitForReviewTool;

/**
 * Registry of every tool the runtime knows (plan D4): the first-party
 * brain/drafts tools now, connector-generated tools in PR 2. Bind an
 * instance in the container (`app()->instance(ToolCatalogue::class, …)`)
 * to share registrations across a process (tests do this).
 */
final class ToolCatalogue
{
    /**
     * The tools PR 1 runs on with every other flag off. When `agents.tools`
     * is off ToolGateway allows only these.
     */
    public const CORE = [
        'brain.read',
        'brain.search',
        'drafts.save',
        'drafts.save_sequence',
        'drafts.submit_for_review',
    ];

    /** @var list<class-string<ToolContract>> */
    private const FIRST_PARTY = [
        BrainReadTool::class,
        BrainSearchTool::class,
        DraftsSaveTool::class,
        DraftsSaveSequenceTool::class,
        DraftsSubmitForReviewTool::class,
    ];

    /** @var array<string, ToolContract> */
    private array $tools = [];

    public function __construct()
    {
        foreach (self::FIRST_PARTY as $class) {
            $this->register(app($class));
        }
    }

    public function register(ToolContract $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?ToolContract
    {
        return $this->tools[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /** @return array<string, ToolContract> */
    public function all(): array
    {
        return $this->tools;
    }

    public function isCore(string $name): bool
    {
        return in_array($name, self::CORE, true);
    }

    /** Estimated USD per call from config/agents.php `tool_costs`. */
    public function costOf(string $name): float
    {
        $costs = (array) config('agents.tool_costs', []);
        if (array_key_exists($name, $costs)) {
            return (float) $costs[$name];
        }
        if (str_starts_with($name, 'connector.') && array_key_exists('connector.*', $costs)) {
            return (float) $costs['connector.*'];
        }
        // Workspace-local drafts tools cost nothing unless config says otherwise
        // (config/agents.php lists drafts.save but not drafts.save_sequence).
        if (str_starts_with($name, 'drafts.')) {
            return 0.0;
        }

        return (float) ($costs['default'] ?? 0.002);
    }

    /**
     * Function-calling style schemas, optionally restricted to an allowlist.
     *
     * @param  list<string>|null  $allowlist
     * @return list<array{name: string, description: string, parameters: array<string, mixed>, risk: string, requires_connector: ?string}>
     */
    public function jsonSchemas(?array $allowlist = null): array
    {
        $out = [];
        foreach ($this->tools as $name => $tool) {
            if ($allowlist !== null && ! in_array($name, $allowlist, true)) {
                continue;
            }
            $out[] = [
                'name' => $name,
                'description' => $tool->description(),
                'parameters' => $tool->schema() ?: ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => true],
                'risk' => $tool->risk(),
                'requires_connector' => $tool->requiresConnector(),
            ];
        }

        return $out;
    }

    /**
     * MCP `tools/list` shaped manifest for intelligence/mcp_server.py.
     *
     * @param  list<string>|null  $allowlist
     * @return array{name: string, version: string, tools: list<array<string, mixed>>}
     */
    public function mcpManifest(?array $allowlist = null): array
    {
        return [
            'name' => 'spidernet-tools',
            'version' => '1.0',
            'tools' => array_map(static fn (array $schema) => [
                'name' => $schema['name'],
                'description' => $schema['description'],
                'inputSchema' => $schema['parameters'],
                'annotations' => [
                    'risk' => $schema['risk'],
                    'readOnlyHint' => $schema['risk'] === ToolContract::RISK_READ,
                    'destructiveHint' => $schema['risk'] === ToolContract::RISK_IRREVERSIBLE,
                    'requiresConnector' => $schema['requires_connector'],
                ],
            ], $this->jsonSchemas($allowlist)),
        ];
    }
}
