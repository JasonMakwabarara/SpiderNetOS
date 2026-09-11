<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesTenant;
use App\Models\PartnerProspect;
use App\Services\FeatureFlag;
use App\Services\Outreach\Affonso\AffonsoMcpClient;
use App\Services\Outreach\Import\ProspectImportService;
use App\Services\Outreach\Ops\OutreachHealth;
use Illuminate\Console\Command;

/**
 * Pull the Affonso Finder shortlist over MCP into partner_prospects, the same
 * way the CSV import does (dedupe on the canonical profile URL, Finder emails
 * accepted through the validator). Behind outreach.finder_sync because the MCP
 * contract is only verified by `outreach:spike finder`; the CSV path stays the
 * guaranteed route.
 */
class OutreachFinderSync extends Command
{
    use ResolvesTenant;

    protected $signature = 'outreach:finder-sync
        {tenant : Tenant slug or UUID}
        {--dry-run : Report what would be imported without writing}
        {--limit=100 : Shortlist items to request}
        {--write-back : Push our status back onto the shortlist item (needs outreach.finder_writeback)}
        {--force : Run even when the outreach.finder_sync flag is off}';

    protected $description = 'Import the Affonso Finder shortlist over MCP (flagged; CSV import is the fallback)';

    public function handle(OutreachHealth $health, ProspectImportService $imports): int
    {
        $tenant = $this->resolveTenant((string) $this->argument('tenant'));
        if ($tenant === null) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }
        $tenantId = (string) $tenant->id;

        if (! $this->option('force') && ! FeatureFlag::on('outreach.finder_sync', $tenantId)) {
            $this->warn('outreach.finder_sync is off for this tenant. Use --force, or: php artisan outreach:enable finder --tenant='.$tenant->slug);

            return self::SUCCESS;
        }

        $credentials = $health->credentials($tenantId, 'affonso');
        if (trim((string) ($credentials['api_key'] ?? '')) === '') {
            $this->error('No Affonso API key: connect the Affonso connector first.');

            return self::FAILURE;
        }

        $client = AffonsoMcpClient::fromCredentials($credentials);

        try {
            $page = $client->shortlist(['limit' => (int) $this->option('limit')]);
        } catch (\Throwable $e) {
            $this->error('Finder pull failed: '.$e->getMessage());
            $this->line('Run `php artisan outreach:spike finder '.$tenant->slug.'` to see the raw handshake, or export the shortlist as CSV and use outreach:import.');

            return self::FAILURE;
        }

        $items = (array) $page['items'];
        if ($items === []) {
            $this->warn('The shortlist came back empty. Check the tool response with: php artisan outreach:spike finder '.$tenant->slug);

            return self::SUCCESS;
        }

        $report = $imports->importRows($tenantId, $items, (bool) $this->option('dry-run'), 'finder');
        $this->info($report->summary());

        if ($this->option('write-back')) {
            $this->writeBack($client, $tenantId, $items);
        }

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $items */
    private function writeBack(AffonsoMcpClient $client, string $tenantId, array $items): void
    {
        if (! FeatureFlag::on('outreach.finder_writeback', $tenantId)) {
            $this->warn('outreach.finder_writeback is off: nothing written back.');

            return;
        }

        $map = [
            PartnerProspect::STATUS_INVITED => 'Contacted', PartnerProspect::STATUS_NUDGED => 'Contacted',
            PartnerProspect::STATUS_LAST_CALLED => 'Contacted', PartnerProspect::STATUS_DM_SENT => 'Contacted',
            PartnerProspect::STATUS_REPLIED => 'Replied', PartnerProspect::STATUS_NEGOTIATING => 'Replied',
            PartnerProspect::STATUS_HANDOFF => 'Replied', PartnerProspect::STATUS_SIGNED_UP => 'Signed up',
            PartnerProspect::STATUS_UNSUBSCRIBED => 'Unsubscribed', PartnerProspect::STATUS_DECLINED => 'Unsubscribed',
        ];
        $pushed = 0;

        foreach ($items as $item) {
            $externalId = trim((string) ($item['external_id'] ?? ''));
            if ($externalId === '') {
                continue;
            }
            $status = (string) PartnerProspect::forTenant($tenantId)
                ->where('affonso_shortlist_item_id', $externalId)->value('status');
            $target = $map[$status] ?? null;
            if ($target === null) {
                continue;
            }

            try {
                $client->updateItem($externalId, $target);
                $pushed++;
            } catch (\Throwable $e) {
                $this->warn('write-back failed for '.$externalId.': '.$e->getMessage());
            }
        }

        $this->line("Wrote our status back onto {$pushed} shortlist item(s).");
    }
}
