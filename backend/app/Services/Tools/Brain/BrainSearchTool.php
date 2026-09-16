<?php

declare(strict_types=1);

namespace App\Services\Tools\Brain;

use App\Models\BrainFile;
use App\Services\Agents\RunContext;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolResult;

/**
 * Case-insensitive LIKE search over the tenant's brain files (path, title,
 * content). The pgsql-only embedding retriever (BrainRetriever, PR 3) will
 * sit in front of this; the LIKE path keeps SQLite green (ADR-0002 D2).
 */
final class BrainSearchTool implements ToolContract
{
    public function name(): string
    {
        return 'brain.search';
    }

    public function description(): string
    {
        return 'Search the business brain for files mentioning a phrase. Returns path, title and a short excerpt per hit.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'minLength' => 2],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 5],
                'folder' => ['type' => 'string', 'description' => 'Optional folder prefix, e.g. offer/'],
            ],
            'required' => ['query'],
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
        $query = trim((string) ($params['query'] ?? ''));
        if (mb_strlen($query) < 2) {
            return ToolResult::fail('query_too_short');
        }
        $limit = max(1, min(20, (int) ($params['limit'] ?? 5)));
        $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($query)).'%';

        $builder = BrainFile::forTenant($ctx->tenantId)
            ->where('path', 'not like', 'workspaces/%')
            ->where(function ($q) use ($needle) {
                $q->whereRaw('lower(path) like ?', [$needle])
                    ->orWhereRaw('lower(coalesce(title, \'\')) like ?', [$needle])
                    ->orWhereRaw('lower(content) like ?', [$needle]);
            });

        $folder = trim((string) ($params['folder'] ?? ''));
        if ($folder !== '' && ! str_contains($folder, '..')) {
            $builder->where('path', 'like', rtrim($folder, '/').'/%');
        }

        $hits = $builder->orderBy('path')->limit($limit)->get(['path', 'title', 'content', 'version']);

        return ToolResult::ok([
            'query' => $query,
            'hits' => $hits->map(fn (BrainFile $file) => [
                'path' => $file->path,
                'title' => $file->title,
                'version' => (int) $file->version,
                'excerpt' => self::excerpt((string) $file->content, $query),
            ])->values()->all(),
        ]);
    }

    private static function excerpt(string $content, string $query, int $radius = 160): string
    {
        $pos = mb_stripos($content, $query);
        if ($pos === false) {
            return mb_substr(trim($content), 0, $radius * 2);
        }
        $start = max(0, $pos - $radius);

        return ($start > 0 ? '…' : '').trim(mb_substr($content, $start, $radius * 2 + mb_strlen($query))).'…';
    }
}
