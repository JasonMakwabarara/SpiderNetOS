<?php

declare(strict_types=1);

namespace App\Services\Outreach\Import;

use App\Models\PartnerProspect;
use App\Services\EventStore;
use App\Services\Outreach\Enrichment\ContactEnrichmentChain;
use App\Services\Sales\LeadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns an Affonso Finder shortlist export (CSV) or any row source into
 * partner_prospects + leads. Dedupes on the normalised profile URL, both
 * inside one file and against rows imported earlier; a re-import refreshes
 * URLs and metadata but never touches email, status or timestamps.
 */
class ProspectImportService
{
    /** CSV header (lower-cased, trimmed) => canonical row key. */
    private const HEADER_MAP = [
        'opportunity name' => 'name', 'name' => 'name', 'creator' => 'name',
        'domain' => 'profile_url', 'profile url' => 'profile_url', 'profile' => 'profile_url', 'url' => 'profile_url',
        'primary url' => 'primary_url', 'post url' => 'primary_url',
        'all urls' => 'all_urls', 'urls' => 'all_urls',
        'category' => 'category', 'status' => 'external_status',
        'date added' => 'date_added', 'last updated' => 'last_updated',
        'email' => 'emails', 'emails' => 'emails', 'contact email' => 'emails',
        'notes' => 'notes', 'id' => 'external_id', 'shortlist id' => 'external_id',
    ];

    public function __construct(
        private readonly ProfileUrlNormalizer $urls,
        private readonly LeadService $leads,
        private readonly ContactEnrichmentChain $enrichment,
        private readonly EventStore $events,
    ) {}

    public function importCsv(string $tenantId, string $path, bool $dryRun = false, string $source = 'csv'): ImportReport
    {
        if (! is_readable($path)) {
            throw new \InvalidArgumentException("CSV not readable: {$path}");
        }

        return $this->importRows($tenantId, $this->readCsv($path), $dryRun, $source);
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows  canonical keys: name, profile_url, primary_url,
     *                                                     all_urls (array|string), category, external_status,
     *                                                     date_added, emails (array|string), notes, external_id
     */
    public function importRows(string $tenantId, iterable $rows, bool $dryRun = false, string $source = 'csv'): ImportReport
    {
        $report = new ImportReport;
        $report->dryRun = $dryRun;
        $seen = [];

        foreach ($rows as $index => $row) {
            $report->rows++;

            $normalized = $this->urls->normalize((string) ($row['profile_url'] ?? ''));
            if ($normalized === null) {
                $report->invalid++;
                $report->errors[] = 'row '.($index + 1).': no usable profile URL';

                continue;
            }

            if (isset($seen[$normalized['hash']])) {
                $report->duplicates++;

                continue;
            }
            $seen[$normalized['hash']] = true;

            $emails = $this->listFrom($row['emails'] ?? null);
            if ($emails !== []) {
                $report->withEmail++;
            }

            $existing = PartnerProspect::forTenant($tenantId)->where('profile_url_hash', $normalized['hash'])->first();

            if ($existing !== null) {
                $report->updated++;
                if (! $dryRun) {
                    $this->refresh($existing, $row, $normalized, $emails);
                }

                continue;
            }

            $report->created++;
            if (! $dryRun) {
                $this->create($tenantId, $row, $normalized, $emails, $source);
            }
        }

        return $report;
    }

    /** @param array<string, mixed> $normalized */
    private function create(string $tenantId, array $row, array $normalized, array $emails, string $source): PartnerProspect
    {
        return DB::transaction(function () use ($tenantId, $row, $normalized, $emails, $source) {
            $name = trim((string) ($row['name'] ?? '')) ?: ($normalized['handle'] ?? null);

            $lead = $this->leads->create($tenantId, [
                'name' => $name,
                'source' => 'import',
                'custom' => [
                    'partner_prospect' => true,
                    'platform' => $normalized['platform'],
                    'profile_url' => $normalized['url'],
                ],
            ]);

            $prospect = PartnerProspect::create([
                'tenant_id' => $tenantId,
                'lead_id' => $lead->id,
                'platform' => $normalized['platform'],
                'handle' => $normalized['handle'],
                'profile_url' => $normalized['url'],
                'profile_url_hash' => $normalized['hash'],
                'primary_content_url' => $this->nullableString($row['primary_url'] ?? null, 1000),
                'all_urls' => $this->listFrom($row['all_urls'] ?? null),
                'display_name' => $this->nullableString($name, 190),
                'source' => $source,
                'source_meta' => $this->metaFrom($row, $emails),
                'affonso_shortlist_item_id' => $this->nullableString($row['external_id'] ?? null, 64),
                // Lower-case: mail servers may fold the local part of partners+<token>@.
                'invite_token' => Str::lower(Str::random(22)),
                'status' => PartnerProspect::STATUS_NEW,
            ]);

            $this->events->append($tenantId, 'partner_prospect', (string) $prospect->id, 'outreach.prospect.imported', [
                'prospect_id' => $prospect->id, 'lead_id' => $lead->id, 'platform' => $prospect->platform, 'source' => $source,
            ]);

            // Finder-provided emails (and later enrichers) decide ready vs needs_email.
            $this->enrichment->evaluate($prospect);

            return $prospect->refresh();
        });
    }

    /** Re-import: refresh URLs/metadata only; never email, status or timestamps. */
    private function refresh(PartnerProspect $prospect, array $row, array $normalized, array $emails): void
    {
        $meta = (array) $prospect->source_meta;
        $incoming = $this->metaFrom($row, $emails);
        $meta['emails'] = array_values(array_unique(array_merge((array) ($meta['emails'] ?? []), (array) ($incoming['emails'] ?? []))));
        foreach (['category', 'external_status', 'date_added', 'last_updated', 'notes'] as $key) {
            if (! empty($incoming[$key])) {
                $meta[$key] = $incoming[$key];
            }
        }

        $prospect->fill([
            'all_urls' => array_values(array_unique(array_merge((array) $prospect->all_urls, $this->listFrom($row['all_urls'] ?? null)))),
            'primary_content_url' => $prospect->primary_content_url ?: $this->nullableString($row['primary_url'] ?? null, 1000),
            'display_name' => $prospect->display_name ?: $this->nullableString($row['name'] ?? null, 190),
            'source_meta' => $meta,
            'affonso_shortlist_item_id' => $prospect->affonso_shortlist_item_id ?: $this->nullableString($row['external_id'] ?? null, 64),
        ])->save();

        if ($prospect->lead && ! $prospect->lead->email) {
            $this->enrichment->evaluate($prospect);
        }
    }

    /** @return array<string, mixed> */
    private function metaFrom(array $row, array $emails): array
    {
        return array_filter([
            'emails' => $emails,
            'category' => $this->nullableString($row['category'] ?? null, 64),
            'external_status' => $this->nullableString($row['external_status'] ?? null, 50),
            'date_added' => $this->nullableString($row['date_added'] ?? null, 40),
            'last_updated' => $this->nullableString($row['last_updated'] ?? null, 40),
            'notes' => $this->nullableString($row['notes'] ?? null, 1000),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /** @return list<string> */
    private function listFrom(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[;,\s]+/', trim((string) $value)) ?: [];
        }

        return array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), $items), fn (string $v) => $v !== '')));
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function readCsv(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open {$path}");
        }

        try {
            $headers = null;
            while (($cells = fgetcsv($handle)) !== false) {
                if ($cells === [null]) {
                    continue; // blank line
                }
                if ($headers === null) {
                    $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]) ?? $cells[0];
                    $headers = array_map(fn ($h) => self::HEADER_MAP[strtolower(trim((string) $h))] ?? null, $cells);

                    continue;
                }
                $row = [];
                foreach ($headers as $i => $key) {
                    if ($key !== null && array_key_exists($i, $cells)) {
                        $row[$key] = $cells[$i];
                    }
                }
                yield $row;
            }
        } finally {
            fclose($handle);
        }
    }
}
