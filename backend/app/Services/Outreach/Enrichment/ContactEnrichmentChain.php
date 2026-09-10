<?php

declare(strict_types=1);

namespace App\Services\Outreach\Enrichment;

use App\Models\PartnerProspect;
use App\Services\Outreach\ProspectStateMachine;

/**
 * Runs the enrichers in order and accepts the first address that passes
 * EmailValidator; a prospect with no acceptable address is parked in
 * needs_email for the operator (or the DM queue).
 */
class ContactEnrichmentChain
{
    /** @var list<ContactEnricher> */
    private array $enrichers;

    public function __construct(
        private readonly ProspectStateMachine $lifecycle,
        FinderEmailsEnricher $finder,
        PublicProfileEnricher $profile,
    ) {
        $this->enrichers = [$finder, $profile];
    }

    /**
     * @return array{accepted: ?string, source: ?string, rejected: array<string, string>}
     */
    public function evaluate(PartnerProspect $prospect): array
    {
        $rejected = [];

        if ($prospect->lead && $prospect->lead->email) {
            return ['accepted' => $prospect->lead->email, 'source' => $prospect->email_source, 'rejected' => []];
        }

        foreach ($this->enrichers as $enricher) {
            foreach ($enricher->candidates($prospect) as $candidate) {
                $result = $this->lifecycle->acceptEmail($prospect, $candidate, $enricher->source());
                if ($result['ok']) {
                    return ['accepted' => $result['email'], 'source' => $enricher->source(), 'rejected' => $rejected];
                }
                $rejected[$candidate] = (string) $result['reason'];
            }
        }

        if ($prospect->status === PartnerProspect::STATUS_NEW) {
            $this->lifecycle->transition($prospect, PartnerProspect::STATUS_NEEDS_EMAIL, [], [PartnerProspect::STATUS_NEW]);
        }

        return ['accepted' => null, 'source' => null, 'rejected' => $rejected];
    }
}
