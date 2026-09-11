<?php

declare(strict_types=1);

namespace App\Services\Outreach\Enrichment;

use App\Models\PartnerProspect;

/**
 * Emails that arrived with the import itself: Affonso Finder shortlist items
 * carry an `emails` list (the CSV export usually does not, the MCP pull does).
 */
class FinderEmailsEnricher implements ContactEnricher
{
    public function source(): string
    {
        return 'finder';
    }

    public function candidates(PartnerProspect $prospect): array
    {
        $emails = (array) (((array) $prospect->source_meta)['emails'] ?? []);

        return array_values(array_filter(array_map(fn ($e) => trim((string) $e), $emails), fn (string $e) => $e !== ''));
    }
}
