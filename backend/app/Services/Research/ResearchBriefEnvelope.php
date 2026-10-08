<?php

declare(strict_types=1);

namespace App\Services\Research;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The research-brief contract stored on an agent artifact.
 *
 * The caller supplies the question and the sources they already have.
 * This class does not fetch URLs, does not call a model, and cannot
 * record a tenant or an approval. Every source is stored as
 * operator-supplied and unverified.
 */
final class ResearchBriefEnvelope
{
    public const MAX_BYTES = 1_048_576;

    public const ORIGIN = 'operator_supplied';

    public const EVIDENCE = 'supplied_unverified';

    /** Fields that would let an import choose a tenant or mark itself reviewed. */
    public const FORBIDDEN = [
        'tenant_id',
        'status',
        'approval_id',
        'approval',
        'approved',
        'reviewed',
        'approval_status',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array{title: string, meta: array<string, mixed>, markdown: string}
     *
     * @throws ValidationException
     */
    public static function accept(array $input): array
    {
        self::rejectAuthority($input);

        $validator = Validator::make($input, [
            'title' => 'required|string|max:255',
            'objective' => 'required|string|max:4000',
            'audience' => 'nullable|string|max:2000',
            'supplied_facts' => 'nullable|array|max:100',
            'supplied_facts.*' => 'string|max:2000',
            'sources' => 'nullable|array|max:50',
            'sources.*.title' => 'required|string|max:200',
            'sources.*.url' => 'required|string|max:2048',
            'sources.*.excerpt' => 'nullable|string|max:2000',
            'provenance' => 'nullable|string|max:2000',
            'draft' => 'nullable|string|max:200000',
        ]);

        $validator->after(function ($validator) use ($input): void {
            foreach (array_values((array) ($input['sources'] ?? [])) as $i => $source) {
                if (! is_array($source)) {
                    continue;
                }
                $url = trim((string) ($source['url'] ?? ''));
                if ($url !== '' && ! self::isHttpUrl($url)) {
                    $validator->errors()->add("sources.$i.url", 'Source URLs must use http or https.');
                }
            }
        });

        $validated = $validator->validate();

        $sources = [];
        foreach (array_values((array) ($validated['sources'] ?? [])) as $source) {
            $excerpt = trim((string) ($source['excerpt'] ?? ''));
            $sources[] = [
                'title' => trim((string) $source['title']),
                'url' => trim((string) $source['url']),
                'excerpt' => $excerpt !== '' ? $excerpt : null,
                'origin' => self::ORIGIN,
                'evidence_status' => self::EVIDENCE,
            ];
        }

        $facts = [];
        foreach (array_values((array) ($validated['supplied_facts'] ?? [])) as $fact) {
            $text = trim((string) $fact);
            if ($text !== '') {
                $facts[] = $text;
            }
        }

        $meta = [
            'objective' => trim((string) $validated['objective']),
            'audience' => trim((string) ($validated['audience'] ?? '')),
            'supplied_facts' => $facts,
            'sources' => $sources,
            'provenance' => trim((string) ($validated['provenance'] ?? '')),
            'draft' => trim((string) ($validated['draft'] ?? '')),
            'review_state' => 'unreviewed',
        ];
        $title = trim((string) $validated['title']);

        return [
            'title' => $title,
            'meta' => $meta,
            'markdown' => self::markdown($title, $meta),
        ];
    }

    /** @param  array<string, mixed>  $input */
    public static function rejectAuthority(array $input): void
    {
        $present = array_values(array_intersect(array_keys($input), self::FORBIDDEN));
        if ($present === []) {
            return;
        }

        $errors = [];
        foreach ($present as $key) {
            $errors[$key] = ['A research brief cannot set tenant identity or approval state.'];
        }

        throw ValidationException::withMessages($errors);
    }

    public static function isHttpUrl(string $url): bool
    {
        if (preg_match('/\s/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    /** @param  array<string, mixed>  $meta */
    public static function markdown(string $title, array $meta): string
    {
        $lines = [
            '# '.self::singleLine($title),
            '',
            '> Unreviewed draft. The text below is a suggestion until a person accepts it. Source links are operator-supplied and unverified; a URL is not proof that a claim is supported.',
            '',
            '## Objective',
            '',
            (string) ($meta['objective'] ?? ''),
            '',
            '## Audience',
            '',
            ($meta['audience'] ?? '') !== '' ? (string) $meta['audience'] : '_Not stated._',
            '',
            '## Supplied facts',
            '',
        ];

        $facts = array_values((array) ($meta['supplied_facts'] ?? []));
        if ($facts === []) {
            $lines[] = '_None supplied._';
        } else {
            foreach ($facts as $fact) {
                $lines[] = '- '.self::singleLine((string) $fact);
            }
        }

        $lines[] = '';
        $lines[] = '## Suggested draft';
        $lines[] = '';
        $lines[] = ($meta['draft'] ?? '') !== ''
            ? (string) $meta['draft']
            : '_No draft text was supplied._';
        $lines[] = '';
        $lines[] = '## Source register';
        $lines[] = '';

        $sources = array_values((array) ($meta['sources'] ?? []));
        if ($sources === []) {
            $lines[] = '_No sources supplied._';
        } else {
            foreach ($sources as $source) {
                $source = (array) $source;
                $lines[] = '### '.self::singleLine((string) ($source['title'] ?? 'Untitled source'));
                $lines[] = '';
                $lines[] = '- URL: '.(string) ($source['url'] ?? '');
                $lines[] = '- Origin: '.self::ORIGIN;
                $lines[] = '- Evidence: '.self::EVIDENCE;
                if (($source['excerpt'] ?? null) !== null && $source['excerpt'] !== '') {
                    $lines[] = '- Excerpt: '.self::singleLine((string) $source['excerpt']);
                }
                $lines[] = '';
            }
        }

        $lines[] = '## Provenance';
        $lines[] = '';
        $lines[] = ($meta['provenance'] ?? '') !== ''
            ? (string) $meta['provenance']
            : 'Not stated. Provider identity is self-reported and was not verified.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private static function singleLine(string $value): string
    {
        $flat = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($flat) ? $flat : trim($value);
    }
}
