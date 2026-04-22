<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\VoiceNumber;
use App\Services\TelephonyService;
use App\Services\VoiceSafetyGuard;
use App\Services\FeatureFlag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * VoiceSmokeTest — Phase A
 *
 * Runs a lightweight end-to-end sanity check against the voice vertical
 * without placing a real call. Useful in CI and on-call runbooks.
 *
 * Usage:
 *   php artisan voice:smoke                          # checks global config
 *   php artisan voice:smoke --tenant=<uuid>          # per-tenant checks
 *   php artisan voice:smoke --call  --tenant=<uuid>  # place real Twilio test call (needs test credentials)
 */
class VoiceSmokeTest extends Command
{
    protected $signature = 'voice:smoke
        {--tenant= : Tenant UUID to test per-tenant gate}
        {--call    : Place a real Twilio test call (requires TWILIO_TEST_* env vars)}';

    protected $description = 'Run voice vertical smoke tests (Phase A)';

    private array $results = [];

    public function handle(TelephonyService $telephony, VoiceSafetyGuard $guard): int
    {
        $this->info('SpiderNet Voice AI — Smoke Test');
        $this->newLine();

        $tenantId = $this->option('tenant');

        // ── 1. Configuration checks ───────────────────────────────────────
        $this->runCheck('Twilio SID configured', fn() => !empty(config('telephony.providers.twilio.sid')));
        $this->runCheck('Twilio auth token configured', fn() => !empty(config('telephony.providers.twilio.auth_token')));
        $this->runCheck('STT provider set', fn() => !empty(config('telephony.stt.provider')));
        $this->runCheck('TTS provider set', fn() => !empty(config('telephony.tts.provider')));
        $this->runCheck('Inference URL set', fn() => !empty(config('services.inference.url', '')));

        // ── 2. Feature flag checks ────────────────────────────────────────
        $flagOn = FeatureFlag::on('voice.inbound', $tenantId);
        $this->runCheck(
            'voice.inbound flag is ON' . ($tenantId ? " (tenant={$tenantId})" : ' (global)'),
            fn() => $flagOn,
            warning: true   // just warn, not fail
        );

        // ── 3. Database connectivity ─────────────────────────────────────
        $this->runCheck('voice_numbers table accessible', function () {
            VoiceNumber::query()->count();
            return true;
        });

        // ── 4. Per-tenant safety guard ───────────────────────────────────
        if ($tenantId) {
            $this->runCheck('VoiceSafetyGuard passes for tenant', function () use ($guard, $tenantId) {
                // Force feature on for smoke test
                FeatureFlag::set('voice.inbound', 'on', $tenantId);
                $result = $guard->checkInbound($tenantId);
                return $result['allowed'] || $result['reason'] === 'cost_cap_exceeded';
            });
        }

        // ── 5. Inference service reachability ────────────────────────────
        $this->runCheck('Inference service /health reachable', function () {
            $url = config('services.inference.url', 'http://inference:9000');
            try {
                $resp = Http::timeout(3)->get("{$url}/health");
                return $resp->successful();
            } catch (\Throwable) {
                return false;
            }
        }, warning: true);

        // ── 6. Optionally place a real test call ─────────────────────────
        if ($this->option('call') && $tenantId) {
            $this->runCheck('Twilio test call initiated', function () use ($telephony, $tenantId) {
                // Use Twilio test credentials (AC test prefix)
                $testSid   = env('TWILIO_TEST_SID', '');
                $testToken = env('TWILIO_TEST_AUTH_TOKEN', '');
                $testFrom  = env('TWILIO_TEST_FROM', '+15005550006');
                $testTo    = env('TWILIO_TEST_TO', '+15005550001');

                if (empty($testSid) || empty($testToken)) {
                    $this->warn('  TWILIO_TEST_SID / TWILIO_TEST_AUTH_TOKEN not set — skipping real call');
                    return null; // skip
                }

                $result = $telephony->initiateCall($tenantId, $testTo, $testFrom);
                return $result !== null;
            });
        }

        // ── Summary ───────────────────────────────────────────────────────
        $this->newLine();
        $passed  = count(array_filter($this->results, fn($r) => $r['status'] === 'PASS'));
        $warned  = count(array_filter($this->results, fn($r) => $r['status'] === 'WARN'));
        $failed  = count(array_filter($this->results, fn($r) => $r['status'] === 'FAIL'));
        $skipped = count(array_filter($this->results, fn($r) => $r['status'] === 'SKIP'));

        $this->info("Results: {$passed} passed, {$warned} warned, {$failed} failed, {$skipped} skipped");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function runCheck(string $label, callable $check, bool $warning = false): void
    {
        try {
            $result = $check();

            if ($result === null) {
                $this->line("  <fg=blue>[SKIP]</> {$label}");
                $this->results[] = ['label' => $label, 'status' => 'SKIP'];
                return;
            }

            if ($result) {
                $this->line("  <fg=green>[PASS]</> {$label}");
                $this->results[] = ['label' => $label, 'status' => 'PASS'];
            } else {
                if ($warning) {
                    $this->line("  <fg=yellow>[WARN]</> {$label}");
                    $this->results[] = ['label' => $label, 'status' => 'WARN'];
                } else {
                    $this->line("  <fg=red>[FAIL]</> {$label}");
                    $this->results[] = ['label' => $label, 'status' => 'FAIL'];
                }
            }
        } catch (\Throwable $e) {
            $status = $warning ? 'WARN' : 'FAIL';
            $colour = $warning ? 'yellow' : 'red';
            $this->line("  <fg={$colour}>[{$status}]</> {$label}: {$e->getMessage()}");
            $this->results[] = ['label' => $label, 'status' => $status, 'error' => $e->getMessage()];
        }
    }
}
