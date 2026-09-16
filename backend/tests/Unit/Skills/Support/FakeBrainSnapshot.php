<?php

declare(strict_types=1);

namespace Tests\Unit\Skills\Support;

use App\Services\Skills\SkillCard;

/**
 * Stand-in for App\Services\Brain\BrainSnapshot (final, private constructor,
 * DB-backed): exposes the one method SkillPromptBuilder uses,
 * readAt(path): ?array{content, version, frontmatter}. Files may be given as
 * a markdown string (frontmatter is parsed from it) or as the full array.
 */
final class FakeBrainSnapshot
{
    /** @var array<string, array{content: string, version: int, frontmatter: array<string, mixed>}> */
    private array $files = [];

    /** @param  array<string, string|array{content?: string, version?: int, frontmatter?: array}>  $files */
    public function __construct(array $files = [])
    {
        foreach ($files as $path => $file) {
            if (is_string($file)) {
                $this->files[$path] = ['content' => $file, 'version' => 1, 'frontmatter' => SkillCard::parseFrontmatter($file)];

                continue;
            }
            $content = (string) ($file['content'] ?? '');
            $this->files[$path] = [
                'content' => $content,
                'version' => (int) ($file['version'] ?? 1),
                'frontmatter' => (array) ($file['frontmatter'] ?? SkillCard::parseFrontmatter($content)),
            ];
        }
    }

    public function readAt(string $path): ?array
    {
        return $this->files[trim($path, '/')] ?? null;
    }
}
