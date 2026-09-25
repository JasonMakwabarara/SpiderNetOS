<?php

declare(strict_types=1);

namespace App\Services\Brain;

use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Pure text helpers for brain files (plan D2): YAML frontmatter blocks,
 * `## Heading` sections and the `<!-- managed:start <key> -->` …
 * `<!-- managed:end <key> -->` blocks BrainSyncService owns. No I/O, no
 * container — safe to unit test and to reuse from the cockpit-facing layer.
 *
 * Conventions
 *   - Only level-2 headings (`## Name`) delimit sections; `###` and deeper
 *     belong to the enclosing section's body.
 *   - A section whose body is only HTML comments (the `<!-- missing -->`
 *     marker, managed markers) counts as empty — see proseLength().
 *   - Frontmatter text is preserved byte-for-byte by upsertSection(); only
 *     withFrontmatter() re-serialises it.
 */
final class BrainMarkdown
{
    public const MISSING = '<!-- missing -->';

    /**
     * Split a `---` frontmatter block off the top of the content.
     *
     * @return array{0: array<string, mixed>, 1: string} [frontmatter, body]
     */
    public static function splitFrontmatter(string $content): array
    {
        [$raw, $body] = self::splitRaw($content);
        if ($raw === null) {
            return [[], $body];
        }

        try {
            $parsed = Yaml::parse($raw);
        } catch (\Throwable) {
            $parsed = [];
        }

        return [is_array($parsed) ? self::normalizeScalars($parsed) : [], $body];
    }

    /**
     * Frontmatter scalars in one deterministic shape on every path in and out
     * of the store: ints stay ints, and a float that carries no fraction
     * (30.0 from a YAML or JSON round-trip) comes back as the int it was.
     * Strings, bools and nulls are left exactly as they are.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function normalizeScalars(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::normalizeScalars($value);
            } elseif (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
                $data[$key] = (int) $value;
            }
        }

        return $data;
    }

    /**
     * Same split, but returns the raw YAML text (null when absent) so callers
     * that only touch the body can put it back untouched.
     *
     * @return array{0: ?string, 1: string}
     */
    public static function splitRaw(string $content): array
    {
        $content = self::normalizeNewlines($content);
        if (! str_starts_with($content, "---\n")) {
            return [null, $content];
        }

        $lines = explode("\n", $content);
        $count = count($lines);
        for ($i = 1; $i < $count; $i++) {
            if (rtrim($lines[$i]) === '---') {
                $raw = implode("\n", array_slice($lines, 1, $i - 1));
                $body = implode("\n", array_slice($lines, $i + 1));

                return [$raw, ltrim($body, "\n")];
            }
        }

        // An opening `---` with no closing line is not frontmatter.
        return [null, $content];
    }

    /** @return array<string, mixed> */
    public static function frontmatter(string $content): array
    {
        return self::splitFrontmatter($content)[0];
    }

    public static function body(string $content): string
    {
        return self::splitFrontmatter($content)[1];
    }

    /** @param array<string, mixed> $frontmatter */
    public static function withFrontmatter(array $frontmatter, string $body): string
    {
        $body = ltrim(self::normalizeNewlines($body), "\n");
        if ($frontmatter === []) {
            return $body;
        }

        $yaml = rtrim(Yaml::dump($frontmatter, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE), "\n");

        return "---\n{$yaml}\n---\n\n".$body;
    }

    /**
     * `## Heading` → trimmed body, in document order. Frontmatter excluded.
     *
     * @return array<string, string>
     */
    public static function sections(string $content): array
    {
        $body = self::body($content);
        $sections = [];
        $current = null;
        $buffer = [];

        foreach (explode("\n", self::normalizeNewlines($body)) as $line) {
            if (self::isH2($line, $name)) {
                if ($current !== null) {
                    $sections[$current] = trim(implode("\n", $buffer));
                }
                $current = $name;
                $buffer = [];

                continue;
            }
            if ($current !== null) {
                $buffer[] = $line;
            }
        }
        if ($current !== null) {
            $sections[$current] = trim(implode("\n", $buffer));
        }

        return $sections;
    }

    /** Case-insensitive section lookup; null when the heading is absent. */
    public static function section(string $content, string $heading): ?string
    {
        foreach (self::sections($content) as $name => $body) {
            if (strcasecmp($name, $heading) === 0) {
                return $body;
            }
        }

        return null;
    }

    /**
     * Replace the body of `## Heading` (or append the section when absent),
     * leaving frontmatter, the title line and every other section untouched.
     */
    public static function upsertSection(string $content, string $heading, string $body): string
    {
        [$raw, $md] = self::splitRaw($content);
        $lines = explode("\n", $md);
        $start = null;
        $end = count($lines);

        foreach ($lines as $i => $line) {
            if (! self::isH2($line, $name)) {
                continue;
            }
            if ($start === null) {
                if (strcasecmp($name, $heading) === 0) {
                    $start = $i;
                }

                continue;
            }
            $end = $i;
            break;
        }

        $block = ['## '.trim($heading), '', rtrim(self::normalizeNewlines($body)), ''];

        if ($start === null) {
            $md = rtrim($md, "\n");
            $md = ($md === '' ? '' : $md."\n\n").implode("\n", $block);
        } else {
            array_splice($lines, $start, $end - $start, $block);
            $md = implode("\n", $lines);
        }

        return self::joinRaw($raw, rtrim($md, "\n")."\n");
    }

    /** Append text to the end of a section (creating it when absent). Drops a `<!-- missing -->` marker if present. */
    public static function appendToSection(string $content, string $heading, string $text): string
    {
        $existing = self::section($content, $heading);
        if ($existing === null) {
            return self::upsertSection($content, $heading, $text);
        }

        $existing = trim(str_replace(self::MISSING, '', $existing));
        $body = $existing === '' ? $text : $existing."\n\n".$text;

        return self::upsertSection($content, $heading, $body);
    }

    public static function managedBlock(string $key, string $inner): string
    {
        return "<!-- managed:start {$key} -->\n".rtrim(self::normalizeNewlines($inner))."\n<!-- managed:end {$key} -->";
    }

    public static function hasManagedBlock(string $content, string $key): bool
    {
        return (bool) preg_match(self::managedPattern($key), $content);
    }

    /** Replace the inner text of one managed block in place; content is returned unchanged when the block is absent. */
    public static function replaceManagedBlock(string $content, string $key, string $inner): string
    {
        $replacement = self::managedBlock($key, $inner);

        return (string) preg_replace_callback(self::managedPattern($key), static fn () => $replacement, $content, 1);
    }

    /** True when nothing but headings, comments and managed blocks remains — the whole file is projection-owned. */
    public static function isWhollyManaged(string $content): bool
    {
        $body = self::body($content);
        if (! str_contains($body, '<!-- managed:start ')) {
            return false;
        }
        $stripped = (string) preg_replace('/<!-- managed:start (\S+) -->.*?<!-- managed:end \1 -->/s', '', $body);
        $stripped = (string) preg_replace('/^#{1,6}\s+.*$/m', '', $stripped);
        $stripped = (string) preg_replace('/<!--.*?-->/s', '', $stripped);

        return trim($stripped) === '';
    }

    /** Length of the human-visible prose in a section body (markers, comments and whitespace runs excluded). */
    public static function proseLength(string $sectionBody): int
    {
        $text = (string) preg_replace('/<!--.*?-->/s', '', $sectionBody);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_strlen(trim($text));
    }

    /** First `# Title` line, if any. */
    public static function title(string $content): ?string
    {
        foreach (explode("\n", self::body($content)) as $line) {
            if (preg_match('/^#(?!#)\s+(.+?)\s*$/', $line, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    public static function slug(string $text): string
    {
        $slug = Str::slug($text);

        return $slug === '' ? 'untitled' : $slug;
    }

    public static function normalizeNewlines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private static function isH2(string $line, ?string &$name = null): bool
    {
        if (preg_match('/^##(?!#)\s+(.+?)\s*#*\s*$/', $line, $m)) {
            $name = trim($m[1]);

            return $name !== '';
        }
        $name = null;

        return false;
    }

    private static function managedPattern(string $key): string
    {
        $quoted = preg_quote($key, '/');

        return '/<!-- managed:start '.$quoted.' -->.*?<!-- managed:end '.$quoted.' -->/s';
    }

    private static function joinRaw(?string $raw, string $body): string
    {
        if ($raw === null) {
            return $body;
        }

        return "---\n".$raw."\n---\n\n".ltrim($body, "\n");
    }
}
