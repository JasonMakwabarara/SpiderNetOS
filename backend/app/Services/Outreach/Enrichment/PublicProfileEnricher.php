<?php

declare(strict_types=1);

namespace App\Services\Outreach\Enrichment;

use App\Models\PartnerProspect;
use App\Services\FeatureFlag;

/**
 * Placeholder for reading a public bio / About page for a business email.
 * Fetching other platforms' pages is a terms-of-service grey area, so this
 * stays a no-op until the flag is on AND a reviewed fetcher lands; operators
 * enter addresses by hand in the meantime.
 */
class PublicProfileEnricher implements ContactEnricher
{
    public function source(): string
    {
        return 'fetch';
    }

    public function candidates(PartnerProspect $prospect): array
    {
        if (! FeatureFlag::on('outreach.profile_enrichment', (string) $prospect->tenant_id)) {
            return [];
        }

        // Intentionally empty: no fetcher is shipped yet.
        return [];
    }
}
