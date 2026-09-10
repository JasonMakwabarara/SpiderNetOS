<?php

declare(strict_types=1);

namespace Tests\Feature\Outreach;

use App\Models\PackEntitlement;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Services\Connectors\ConnectorManager;
use App\Services\Messaging\TenantMailerFactory;
use App\Services\Outreach\Import\ImportReport;
use App\Services\Outreach\Import\ProspectImportService;
use App\Services\Outreach\OutreachSettings;
use App\Services\TenantKeyManager;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared fixture: an outreach tenant with the sales-crm entitlement, an admin,
 * test-friendly settings (no quiet hours, no send gap) and, on demand, a
 * connected partner mailbox whose mailer is Mail::fake().
 */
abstract class OutreachTestCase extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Hannah AI', 'slug' => 'hannah-ai-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'automation_level' => 'assisted',
            'onboarding_completed_at' => now(), 'settings' => [],
        ]);

        PackEntitlement::create([
            'tenant_id' => $this->tenant->id, 'pack_id' => 'sales-crm', 'source' => 'granted',
            'provider' => 'manual', 'status' => 'active', 'purchased_at' => now(),
        ]);

        $this->admin = User::create([
            'name' => 'Ops', 'email' => 'ops@'.Str::lower(Str::random(8)).'.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        $settings = app(OutreachSettings::class);
        $settings->seedDefaults($this->tenant);
        $settings->update($this->tenant, [
            'program' => ['join_url' => 'https://hannah.affonso.io/?group=grp1', 'postal_address' => '1 Test Street, Harare'],
            'sending' => [
                'quiet_hours' => ['start' => '00:00', 'end' => '00:00'], 'min_gap_seconds' => 0,
                'per_run_cap' => 10, 'warmup' => [['from_day' => 1, 'cap' => 10]],
            ],
        ]);
        $this->tenant->refresh();
    }

    /** Store zoho_mail credentials and route the tenant mailer to Mail::fake(). */
    protected function connectMailbox(string $from = 'partners@hannah-ai.test', bool $withImap = false): void
    {
        Mail::fake();

        $credentials = [
            'from_address' => $from, 'from_name' => 'Hannah AI Partnerships',
            'smtp_host' => 'smtp.zoho.com', 'smtp_port' => '587', 'smtp_username' => $from, 'smtp_password' => 'app-pass',
        ];
        if ($withImap) {
            $credentials += ['imap_host' => 'imap.zoho.com', 'imap_port' => '993', 'imap_username' => $from, 'imap_password' => 'imap-pass'];
        }

        $ref = app(TenantKeyManager::class)->storeSecret((string) $this->tenant->id, 'integration.zoho_mail', (string) json_encode($credentials));

        TenantIntegration::create([
            'tenant_id' => $this->tenant->id, 'provider' => 'zoho_mail', 'type' => 'email',
            'credentials_ref' => $ref, 'is_active' => true, 'status' => 'connected',
        ]);

        $this->app->instance(TenantMailerFactory::class, new class(app(ConnectorManager::class)) extends TenantMailerFactory
        {
            public function fromCredentials(array $credentials): Mailer
            {
                return Mail::mailer();
            }
        });
    }

    /** Store affonso credentials (API key, program, group, webhook secret) as a connected integration. */
    protected function connectAffonso(string $webhookSecret = 'whsec_test', array $extra = []): void
    {
        $credentials = $extra + [
            'api_key' => 'sk_live_test', 'program_id' => 'prog_1', 'group_id' => 'grp_1',
            'webhook_secret' => $webhookSecret, 'portal_subdomain' => 'hannah',
        ];
        $ref = app(TenantKeyManager::class)->storeSecret((string) $this->tenant->id, 'integration.affonso', (string) json_encode($credentials));

        TenantIntegration::create([
            'tenant_id' => $this->tenant->id, 'provider' => 'affonso', 'type' => 'affiliate',
            'credentials_ref' => $ref, 'is_active' => true, 'status' => 'connected',
        ]);
    }

    /**
     * Write a CSV in the Affonso Finder export shape and return its path.
     *
     * @param  list<array<string, string>>  $rows  keys: name, domain, primary, all, email
     */
    protected function csvFile(array $rows, bool $bom = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'shortlist').'.csv';
        $h = fopen($path, 'wb');
        if ($bom) {
            fwrite($h, "\xEF\xBB\xBF");
        }
        fputcsv($h, ['Opportunity Name', 'Domain', 'Category', 'Status', 'Primary URL', 'All URLs', 'Date Added', 'Last Updated', 'Email']);
        foreach ($rows as $r) {
            fputcsv($h, [
                $r['name'] ?? '', $r['domain'] ?? '', 'google', 'New', $r['primary'] ?? '',
                $r['all'] ?? ($r['primary'] ?? ''), 'Sep 10, 2026', 'Sep 10, 2026', $r['email'] ?? '',
            ]);
        }
        fclose($h);

        return $path;
    }

    protected function import(array $rows, bool $dryRun = false): ImportReport
    {
        return app(ProspectImportService::class)->importCsv((string) $this->tenant->id, $this->csvFile($rows), $dryRun);
    }
}
