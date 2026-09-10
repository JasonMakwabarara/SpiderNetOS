<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PackEntitlement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EventStore;
use App\Services\Outreach\OutreachSettings;
use App\Services\TenantKeyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Create or repair a partner-outreach tenant in one idempotent step:
 * tenant row (onboarding marked complete so the API gate opens), an admin
 * user, a cost budget row, event-signing keys, outreach settings defaults, and
 * the sales-crm pack entitlement granted without a purchase.
 *
 * Re-running is safe: every step checks before it writes.
 */
class OutreachTenantBootstrap extends Command
{
    protected $signature = 'outreach:tenant
        {slug : Tenant slug, e.g. hannah-ai}
        {--name= : Display name (defaults to the slug)}
        {--admin-email= : Admin user to create when missing}
        {--automation-level=assisted : manual | assisted | autonomous}
        {--plan=growth : Plan id stamped on a newly created tenant}
        {--skip-pack : Do not install or grant the sales-crm feature pack}';

    protected $description = 'Bootstrap a partner-outreach tenant (tenant, admin, budget, keys, settings, sales-crm entitlement); idempotent';

    private const LEVELS = ['manual', 'assisted', 'autonomous'];

    public function handle(TenantKeyManager $keys, OutreachSettings $settings, EventStore $events): int
    {
        $slug = Str::slug((string) $this->argument('slug'));
        if ($slug === '') {
            $this->error('slug is required.');

            return self::FAILURE;
        }

        $level = strtolower(trim((string) $this->option('automation-level')));
        if (! in_array($level, self::LEVELS, true)) {
            $this->error('automation-level must be one of: '.implode(', ', self::LEVELS).'.');

            return self::FAILURE;
        }

        if ($slug === 'hannah') {
            // The core agent roster already has a "hannah" (tutor) slug; a tenant
            // with the same name is legal but a permanent source of confusion.
            $this->warn("Slug 'hannah' collides with the core tutor agent slug; prefer 'hannah-ai'.");
        }

        $name = trim((string) $this->option('name')) ?: Str::headline($slug);

        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant === null) {
            $tenant = $this->createTenant($slug, $name, $level, $events);
            $this->info("Created tenant {$tenant->id} ({$slug}).");
        } else {
            $this->line("Tenant {$tenant->id} ({$slug}) already exists.");
            $this->repairTenant($tenant, $name, $level);
        }

        if ($settings->seedDefaults($tenant)) {
            $this->line('Seeded outreach settings defaults (settings.outreach).');
        }

        if (! $this->ensureAdmin($tenant)) {
            return self::FAILURE;
        }

        $this->ensureCostBudget($tenant);

        $seeded = $keys->seedMissingSigningKeys();
        if ($seeded > 0) {
            $this->line("Seeded event-signing keys for {$seeded} tenant(s).");
        }

        if (! $this->option('skip-pack') && ! $this->ensurePack($tenant)) {
            return self::FAILURE;
        }

        $this->summary($tenant);

        return self::SUCCESS;
    }

    private function createTenant(string $slug, string $name, string $level, EventStore $events): Tenant
    {
        $now = now();

        $tenant = Tenant::create([
            'name' => $name,
            'slug' => $slug,
            'plan' => (string) $this->option('plan'),
            'status' => 'active',
            'automation_level' => $level,
            'settings' => ['source' => 'outreach_bootstrap'],
            'onboarding' => $this->onboardingStub($name, $level, $now->toIso8601String()),
            'onboarding_completed_at' => $now,
        ]);

        $events->append(
            (string) $tenant->id,
            'tenant',
            (string) $tenant->id,
            'tenant.created',
            ['source' => 'outreach_bootstrap', 'slug' => $slug, 'automation_level' => $level],
        );

        return $tenant;
    }

    private function repairTenant(Tenant $tenant, string $name, string $level): void
    {
        $changes = [];

        if ($tenant->automation_level !== $level) {
            $tenant->automation_level = $level;
            $changes[] = "automation_level set to {$level}";
        }

        if ($tenant->status !== 'active') {
            $tenant->status = 'active';
            $changes[] = 'status set to active';
        }

        if ($tenant->onboarding_completed_at === null) {
            $onboarding = (array) ($tenant->onboarding ?? []);
            $tenant->onboarding = $onboarding + $this->onboardingStub($name, $level, now()->toIso8601String());
            $tenant->onboarding_completed_at = now();
            $changes[] = 'onboarding marked complete';
        }

        if ($changes !== []) {
            $tenant->save();
            $this->line('Repaired: '.implode(', ', $changes).'.');
        }
    }

    /**
     * The five persisted onboarding steps the cockpit's complete() endpoint
     * requires, filled with the bootstrap values so the tenant never has to
     * click through the wizard.
     *
     * @return array<string, mixed>
     */
    private function onboardingStub(string $name, string $level, string $startedAt): array
    {
        return [
            '_v' => 1,
            'tenant' => ['started_at' => $startedAt, 'source' => 'outreach_bootstrap'],
            'budget' => ['daily_limit' => 10, 'monthly_limit' => 100],
            'invites' => [],
            'strictness' => ['automation_level' => $level],
            'branding' => ['business_name' => $name],
        ];
    }

    private function ensureCostBudget(Tenant $tenant): void
    {
        $exists = DB::table('cost_budgets')->where('tenant_id', $tenant->id)->exists();
        if ($exists) {
            return;
        }

        DB::table('cost_budgets')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) $tenant->id,
            'daily_limit' => 10.00,
            'monthly_limit' => 100.00,
            'alert_threshold' => 0.80,
            'action_at_limit' => 'block',
            'notifications' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->line('Created cost budget (10.00/day, 100.00/month).');
    }

    private function ensureAdmin(Tenant $tenant): bool
    {
        $email = strtolower(trim((string) $this->option('admin-email')));

        if ($email === '') {
            $hasAdmin = User::where('tenant_id', $tenant->id)->whereIn('role', ['admin', 'super_admin'])->exists();
            if (! $hasAdmin) {
                $this->warn('No admin user exists for this tenant and --admin-email was not given; nobody can log into the cockpit yet.');
            }

            return true;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("'{$email}' is not a valid email address.");

            return false;
        }

        // users are unique per (tenant, email), so look up within this tenant.
        $user = User::where('tenant_id', $tenant->id)->where('email', $email)->first();

        if ($user === null) {
            if (User::where('email', $email)->where('tenant_id', '!=', $tenant->id)->exists()) {
                $this->warn("{$email} also exists in another tenant; sign-in resolves by email, so a tenant-specific address is safer.");
            }

            User::create([
                'tenant_id' => $tenant->id,
                'name' => $tenant->name.' Admin',
                'email' => $email,
                // Unusable random password: the first login goes through the
                // password-reset flow, never a credential printed to a console.
                'password' => Hash::make(Str::random(40)),
                'role' => 'admin',
                'email_verified_at' => now(),
                'onboarding_completed_at' => now(),
            ]);
            $this->info("Created admin {$email} (set the password via the reset flow).");

            return true;
        }

        if ($user->onboarding_completed_at === null) {
            $user->onboarding_completed_at = now();
            $user->save();
            $this->line("Marked onboarding complete for {$email}.");
        }

        return true;
    }

    private function ensurePack(Tenant $tenant): bool
    {
        $alreadyEntitled = PackEntitlement::forTenant((string) $tenant->id)
            ->where('pack_id', 'sales-crm')
            ->active()
            ->exists();

        // --grant writes the entitlement without a Dodo purchase (updateOrCreate,
        // so re-runs are no-ops); --force re-stages the pack files from the repo.
        $exit = $this->call('spidernet:pack-install', [
            'pack_id' => 'sales-crm',
            '--tenant' => (string) $tenant->id,
            '--grant' => true,
            '--force' => true,
        ]);

        if ($exit !== self::SUCCESS) {
            $this->error('sales-crm pack install failed; the /api/sales routes stay closed for this tenant.');

            return false;
        }

        if (! $alreadyEntitled) {
            $this->info('Granted the sales-crm entitlement (no purchase required).');
        }

        return true;
    }

    private function summary(Tenant $tenant): void
    {
        $entitled = PackEntitlement::forTenant((string) $tenant->id)->where('pack_id', 'sales-crm')->active()->exists();

        $this->newLine();
        $this->info('Outreach tenant ready.');
        $this->table(['Field', 'Value'], [
            ['tenant_id', (string) $tenant->id],
            ['slug', (string) $tenant->slug],
            ['automation_level', (string) $tenant->automation_level],
            ['sales-crm entitled', $entitled ? 'yes' : 'no'],
        ]);
        $this->line('Next: in the cockpit connect "Affonso" and "Zoho Mail (partner mailbox)" under Connectors, then enable sending with');
        $this->line("  php artisan feature set outreach.sending on --tenant={$tenant->id}");
    }
}
