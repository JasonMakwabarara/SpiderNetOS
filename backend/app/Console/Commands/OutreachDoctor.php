<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesTenant;
use App\Models\Tenant;
use App\Services\Outreach\Ops\OutreachHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Go/no-go for partner outreach: what is configured, what is missing, the one
 * command that fixes each gap, and which stage may be switched on next.
 */
class OutreachDoctor extends Command
{
    use ResolvesTenant;

    protected $signature = 'outreach:doctor
        {tenant? : Tenant slug or UUID (default: every tenant with outreach configured)}
        {--probe : Also check that this APP_URL answers /api/health over HTTPS}
        {--json : Machine-readable output}';

    protected $description = 'Pre-flight the partner-outreach pipeline for a tenant and print the next action';

    public function handle(OutreachHealth $health): int
    {
        $tenants = $this->resolveTenants((string) $this->argument('tenant'));

        if ($tenants->isEmpty()) {
            $this->error('No matching tenant with outreach settings. Run: php artisan outreach:tenant <slug> --name=… --admin-email=…');

            return self::FAILURE;
        }

        $reports = $tenants->map(fn (Tenant $t) => $health->report($t))->all();

        if ($this->option('probe')) {
            $probe = $this->probe();
            foreach ($reports as $i => $report) {
                $reports[$i]['ingress'] = $probe;
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($reports as $report) {
            $this->render($report);
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $report */
    private function render(array $report): void
    {
        $tenant = (array) $report['tenant'];
        $stats = (array) $report['stats'];

        $this->newLine();
        $this->line("<options=bold>{$tenant['name']}</> ({$tenant['slug']})  automation={$tenant['automation_level']}  replies={$tenant['reply_mode']}");

        $this->table(['', 'Check', 'Detail'], array_map(fn (array $c) => [
            match ($c['status']) {
                'ok' => '<fg=green>ok</>', 'warn' => '<fg=yellow>warn</>', default => '<fg=red>FAIL</>'
            },
            $c['key'],
            $c['detail'],
        ], (array) $report['checks']));

        $flags = (array) $report['flags'];
        $this->line('Flags: '.implode('  ', array_map(
            fn (string $k) => ($flags[$k] ? '<fg=green>'.$k.'=on</>' : '<fg=gray>'.$k.'=off</>'),
            array_keys($flags),
        )));

        $this->line(sprintf(
            'Prospects: %d total · %d ready · %d need an email · %d signed up | 24h: %d sent, %d replies, %d signups, %d bounced',
            $stats['total'], $stats['ready'], $stats['needs_email'], $stats['signed_up'],
            $stats['sent_24h'], $stats['replies_24h'], $stats['signups_24h'], $stats['bounced_24h'],
        ));

        if (isset($report['ingress'])) {
            $ingress = (array) $report['ingress'];
            $this->line(($ingress['ok'] ? '<fg=green>Ingress ok</>' : '<fg=red>Ingress FAIL</>')
                .': '.$ingress['url'].' → '.$ingress['detail']);
        }

        $this->renderNextActions($report);
    }

    /** The ordered list of things to do next, fixes before flag flips. */
    private function renderNextActions(array $report): void
    {
        $checks = collect((array) $report['checks'])->keyBy('key');
        $blocking = (array) $report['blocking'];
        $flags = (array) $report['flags'];
        $slug = ((array) $report['tenant'])['slug'];
        $actions = [];

        foreach (OutreachHealth::STAGES as $stage) {
            foreach ((array) $blocking[$stage] as $key) {
                $fix = $checks[$key]['fix'] ?? null;
                if ($fix !== null && ! in_array($fix, $actions, true)) {
                    $actions[] = $fix;
                }
            }
        }

        if ($blocking['send'] === [] && ! $flags['sending']) {
            $actions[] = "php artisan outreach:enable send --tenant={$slug}   (warm-up starts today: 5/day, then 15, then 30)";
        }
        if ($blocking['send'] === [] && $flags['sending'] && ! $flags['bot_replies'] && $blocking['bot'] === []) {
            $actions[] = "php artisan outreach:enable bot --tenant={$slug}   (approve mode: every reply is yours to clear)";
        }
        if ($flags['bot_replies'] && ! $flags['affonso_actions'] && $blocking['affiliate'] === []) {
            $actions[] = "php artisan outreach:enable affiliate --tenant={$slug}   (lets the bot create the Affonso affiliate on request)";
        }
        if (! $flags['digest']) {
            $actions[] = "php artisan outreach:enable digest --tenant={$slug}   (daily 08:30 summary + bounce auto-pause)";
        }

        $warnings = collect((array) $report['checks'])->where('status', 'warn')->pluck('fix')->filter()->all();

        if ($actions === [] && $warnings === []) {
            $this->info('Nothing to do: this tenant is fully configured and running.');

            return;
        }

        $this->newLine();
        $this->line('<options=bold>Next:</>');
        foreach ($actions as $i => $action) {
            $this->line('  '.($i + 1).'. '.$action);
        }
        foreach ($warnings as $warning) {
            $this->line('  <fg=yellow>·</> '.$warning);
        }
    }

    /** @return array{ok: bool, url: string, detail: string} */
    private function probe(): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $url = $base.'/api/health';

        if (! str_starts_with($base, 'https://')) {
            return ['ok' => false, 'url' => $url, 'detail' => 'APP_URL is not https; unsubscribe links and the Affonso webhook need a public HTTPS host'];
        }

        try {
            $response = Http::timeout(15)->acceptJson()->get($url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'url' => $url, 'detail' => 'unreachable: '.mb_substr($e->getMessage(), 0, 120)];
        }

        $status = (string) $response->json('status', '');

        return [
            'ok' => $response->successful() && $status === 'healthy',
            'url' => $url,
            'detail' => $response->status().' '.($status !== '' ? $status : mb_substr($response->body(), 0, 80)),
        ];
    }
}
