<?php

declare(strict_types=1);

namespace App\Services\Outreach\Enrichment;

use App\Models\PartnerProspect;

/**
 * One source of contact details for a prospect. Enrichers never write; the
 * ContactEnrichmentChain validates candidates and records the accepted one.
 */
interface ContactEnricher
{
    /** Short machine name recorded as partner_prospects.email_source. */
    public function source(): string;

    /** @return list<string> candidate email addresses, best first (may be empty) */
    public function candidates(PartnerProspect $prospect): array;
}
