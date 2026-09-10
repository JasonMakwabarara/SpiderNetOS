<?php

declare(strict_types=1);

namespace App\Services\Outreach\Import;

/**
 * Turns the many spellings of a creator's profile URL into one canonical form
 * so re-imports and overlapping Finder exports dedupe, and derives the
 * platform + handle the templates personalise on.
 */
class ProfileUrlNormalizer
{
    /**
     * @return array{url: string, hash: string, platform: string, handle: ?string}|null
     *                                                                                  null when the value is not a usable http(s) URL
     */
    public function normalize(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (! preg_match('~^https?://~i', $raw)) {
            $raw = 'https://'.$raw;
        }

        $parts = parse_url($raw);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        // parse_url() is lenient ("not a url at all" yields a host); require a real hostname.
        if (! preg_match('~^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$~', $host)) {
            return null;
        }
        $host = preg_replace('~^(www|m|mobile|web)\.~', '', $host) ?? $host;

        $path = rtrim($parts['path'] ?? '/', '/');
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        if ($path === '') {
            $path = '/';
        }

        $platform = $this->platformFor($host);

        // TikTok, Facebook and Instagram handles are case-insensitive, so the
        // canonical URL (and the handle derived from it) are lower-cased.
        $canonicalPath = in_array($platform, ['tiktok', 'facebook', 'instagram'], true) ? strtolower($path) : $path;
        $handle = $this->handleFor($platform, $canonicalPath);
        $url = 'https://'.$host.($canonicalPath === '/' ? '/' : $canonicalPath);

        return [
            'url' => $url,
            'hash' => hash('sha256', $url),
            'platform' => $platform,
            'handle' => $handle,
        ];
    }

    public function platformFor(string $host): string
    {
        return match (true) {
            str_ends_with($host, 'tiktok.com') => 'tiktok',
            str_ends_with($host, 'facebook.com'), str_ends_with($host, 'fb.com') => 'facebook',
            str_ends_with($host, 'instagram.com') => 'instagram',
            str_ends_with($host, 'youtube.com'), str_ends_with($host, 'youtu.be') => 'youtube',
            str_ends_with($host, 'x.com'), str_ends_with($host, 'twitter.com') => 'x',
            str_ends_with($host, 'linkedin.com') => 'linkedin',
            default => 'web',
        };
    }

    private function handleFor(string $platform, string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', $path), fn (string $s) => $s !== ''));
        $first = $segments[0] ?? null;
        if ($first === null) {
            return null;
        }

        return match ($platform) {
            'tiktok', 'instagram', 'youtube', 'x' => str_starts_with($first, '@') ? mb_substr($first, 1) : null,
            'facebook' => in_array(strtolower($first), ['groups', 'pages', 'profile.php', 'people'], true)
                ? ($segments[1] ?? null)
                : $first,
            'linkedin' => in_array(strtolower($first), ['in', 'company'], true) ? ($segments[1] ?? null) : null,
            default => null,
        };
    }

    /** Facebook groups get their own source type for template copy. */
    public function isFacebookGroup(string $url): bool
    {
        return (bool) preg_match('~facebook\.com/groups/~i', $url);
    }
}
