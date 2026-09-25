<?php

declare(strict_types=1);

namespace App\Services\Tools\Brain;

use App\Services\Agents\Collaborators;
use App\Services\Agents\RunContext;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolResult;

/**
 * Read one Knowledge-brain path. Reads come from the run's pinned
 * BrainSnapshot when it holds the path (so a run never sees the brain move
 * under it), else the current head row.
 */
final class BrainReadTool implements ToolContract
{
    public function name(): string
    {
        return 'brain.read';
    }

    public function description(): string
    {
        return 'Read a file from the business brain by path (e.g. brand/voice.md). Returns its markdown content, version and frontmatter.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Brain path, e.g. offer/offer.md'],
                'section' => ['type' => 'string', 'description' => 'Optional "## Heading" to return on its own'],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ];
    }

    public function risk(): string
    {
        return self::RISK_READ;
    }

    public function requiresConnector(): ?string
    {
        return null;
    }

    public function execute(RunContext $ctx, array $params): ToolResult
    {
        $path = trim((string) ($params['path'] ?? ''));
        if ($path === '' || str_contains($path, '..')) {
            return ToolResult::fail('invalid_path');
        }

        $file = Collaborators::readBrainPath($ctx->tenantId, $path, $ctx->snapshot);
        if ($file === null) {
            return ToolResult::fail('not_found', ['path' => $path]);
        }

        $section = trim((string) ($params['section'] ?? ''));
        if ($section !== '') {
            $body = self::section($file['content'], $section);
            if ($body === null) {
                return ToolResult::fail('section_not_found', ['path' => $path, 'section' => $section]);
            }
            $file['content'] = $body;
            $file['section'] = $section;
        }

        return ToolResult::ok($file);
    }

    /** Body of a `## Heading` section (until the next heading of the same or higher level). */
    public static function section(string $markdown, string $heading): ?string
    {
        $pattern = '/^(#{1,6})\s+'.preg_quote($heading, '/').'\s*$/mi';
        if (! preg_match($pattern, $markdown, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $level = strlen($m[1][0]);
        $start = $m[0][1] + strlen($m[0][0]);
        $rest = substr($markdown, $start);
        if (preg_match('/^#{1,'.$level.'}\s+/m', $rest, $n, PREG_OFFSET_CAPTURE)) {
            $rest = substr($rest, 0, $n[0][1]);
        }

        return trim($rest);
    }
}
