<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesTenant;
use App\Models\ConversationMessage;
use App\Models\Tenant;
use App\Services\FeatureFlag;
use App\Services\Outreach\Bot\RecruiterBot;
use App\Services\Outreach\Ops\OutreachHealth;
use App\Services\Outreach\OutreachSettings;
use Illuminate\Console\Command;

/**
 * The rollout switch, and the only one an operator needs. Each stage lifts the
 * flags for one step of the ladder, refuses to move while a blocking
 * pre-flight check fails (outreach:doctor explains), and reverses with --off.
 *
 *   send      invites start going out at the warm-up cadence
 *   bot       the recruiter bot drafts replies for approval
 *   affiliate the bot may create the Affonso affiliate when asked
 *   digest    daily 08:30 summary + bounce-rate auto-pause
 *   auto      bot replies send without an approval (needs a clean approval record)
 */
class OutreachEnable extends Command
{
    use ResolvesTenant;

    /** Minimum approved drafts, none of them edited, before auto mode is sensible. */
    public const AUTO_CLEAN_DRAFTS = 20;

    private const FLAGS = [
        'send' => ['outreach.enabled', 'outreach.sending', 'outreach.inbound_poll'],
        'bot' => ['outreach.bot_replies'],
        'affiliate' => ['outreach.affonso_actions'],
        'digest' => ['outreach.digest'],
        'finder' => ['outreach.finder_sync'],
    ];

    private const GATE = ['send' => 'send', 'bot' => 'bot', 'affiliate' => 'affiliate', 'auto' => 'bot'];

    protected $signature = 'outreach:enable
        {stage : send|bot|affiliate|digest|finder|auto}
        {--tenant= : Tenant slug or UUID (required)}
        {--off : Turn this stage back off instead}
        {--force : Lift the flag even though a pre-flight check fails}';

    protected $description
        = 'Advance (or roll back) one stage of the partner-outreach rollout, pre-flight checks enforced';

    public function handle(OutreachHealth $health, OutreachSettings $settings): int
    {
        $stage = (string) $this->argument('stage');
        $off = (bool) $this->option('off');

        if (! array_key_exists($stage, self::FLAGS) && $stage !== 'auto') {
            $this->error("Unknown stage '{$stage}'. One of: ".implode(', ', [...array_keys(self::FLAGS), 'auto']));

            return self::FAILURE;
        }

        $tenant = $this->resolveTenant((string) $this->option('tenant'));
        if ($tenant === null) {
            $this->error('Pass an existing tenant: --tenant=<slug|uuid>');

            return self::FAILURE;
        }
        $tenantId = (string) $tenant->id;

        if (! $off && ! $this->preflight($health, $tenant, $stage)) {
            return self::FAILURE;
        }

        if ($stage === 'auto') {
            return $this->switchMode($settings, $tenant, $off);
        }

        // Day 1 of the warm-up ladder is the day sending is first switched on.
        if ($stage === 'send' && ! $off) {
            $sending = (array) $settings->for($tenant)['sending'];
            if (empty($sending['started_at'])) {
                $settings->update($tenant, ['sending' => ['started_at' => now()->toIso8601String()]]);
                $this->line('Warm-up clock started: '.$this->ladder($settings, $tenant->refresh()));
            } else {
                $this->line('Warm-up already running since '.$sending['started_at'].': '.$this->ladder($settings, $tenant));
            }
        }

        foreach (self::FLAGS[$stage] as $flag) {
            try {
                FeatureFlag::set($flag, $off ? 'off' : 'on', $tenantId);
            } catch (\Throwable $e) {
                // Overrides live in Redis; without it the flag keeps its config default.
                $this->error('Could not write '.$flag.': '.$e->getMessage());
                $this->line('Flags are stored in Redis — check it is reachable, then re-run.');

                return self::FAILURE;
            }
            $this->line(($off ? '<fg=yellow>off</> ' : '<fg=green>on</>  ').$flag.' (tenant '.$tenant->slug.')');
        }

        $this->newLine();
        $this->info($off
            ? "Stage '{$stage}' is off. Nothing queued for it will run."
            : "Stage '{$stage}' is live. Check it with: php artisan outreach:doctor {$tenant->slug}");

        return self::SUCCESS;
    }

    /** Blocking checks for this stage must pass (or --force). */
    private function preflight(OutreachHealth $health, Tenant $tenant, string $stage): bool
    {
        $gate = self::GATE[$stage] ?? null;
        if ($gate === null) {
            return true;
        }

        $report = $health->report($tenant);
        $blocking = (array) ((array) $report['blocking'])[$gate];
        if ($blocking === []) {
            return true;
        }

        $checks = collect((array) $report['checks'])->keyBy('key');
        $this->error("Not ready for '{$stage}': ".implode(', ', $blocking));
        foreach ($blocking as $key) {
            $this->line('  · '.($checks[$key]['detail'] ?? $key));
            if (! empty($checks[$key]['fix'])) {
                $this->line('    fix: '.$checks[$key]['fix']);
            }
        }

        if ($this->option('force')) {
            $this->warn('--force given: lifting the flag anyway.');

            return true;
        }

        $this->newLine();
        $this->line('Re-run after fixing, or pass --force if you know better.');

        return false;
    }

    /** auto mode: bot replies send themselves. Gated on a clean approval record. */
    private function switchMode(OutreachSettings $settings, Tenant $tenant, bool $off): int
    {
        if ($off) {
            $settings->update($tenant, ['replies' => ['mode' => 'approve']]);
            $this->info('Reply mode is back to approve: every bot reply waits for you.');

            return self::SUCCESS;
        }

        $drafts = ConversationMessage::forTenant((string) $tenant->id)->where('sent_by', RecruiterBot::SENT_BY)
            ->where('status', 'approved')->get(['draft_meta']);
        $edited = $drafts->filter(fn (ConversationMessage $m) => ! empty(((array) $m->draft_meta)['edited_by']))->count();

        if (($drafts->count() < self::AUTO_CLEAN_DRAFTS || $edited > 0) && ! $this->option('force')) {
            $this->error(sprintf(
                'Not yet: %d approved draft(s), %d of them edited. Auto mode wants %d consecutive clean ones.',
                $drafts->count(), $edited, self::AUTO_CLEAN_DRAFTS,
            ));
            $this->line('Keep clearing approvals, or pass --force to switch anyway.');

            return self::FAILURE;
        }

        $settings->update($tenant, ['replies' => ['mode' => 'auto']]);
        $this->info('Reply mode is auto: the bot now sends its own replies. Handoffs still come to you.');
        $this->line('Roll back with: php artisan outreach:enable auto --tenant='.$tenant->slug.' --off');

        return self::SUCCESS;
    }

    private function ladder(OutreachSettings $settings, Tenant $tenant): string
    {
        $warmup = (array) ((array) $settings->for($tenant)['sending'])['warmup'];

        return implode(', ', array_map(
            fn (array $step) => 'day '.$step['from_day'].'+ → '.$step['cap'].'/day',
            $warmup,
        ));
    }
}
