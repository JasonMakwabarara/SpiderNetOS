<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MessagingNumber;
use App\Models\PackEntitlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Replicates Hannah's guidance pattern (intelligence/agents/hannah_agent.py +
 * cockpit HannahGuidancePanel.vue: a list of "next step" items, each either
 * fixable directly, linked to docs, or forwarded as a natural-language
 * prompt into Atlas chat) — applied to closing the gaps between "the code
 * is built" and "this is actually live" for the Lead-to-Sale Funnel bundle.
 *
 * Deterministic checks only (no LLM call) — these are objective technical
 * facts, not open-ended questions. The "ask_hannah_prompt" on each item is
 * what gets forwarded into Atlas chat when the operator wants Hannah's
 * conversational how-to guidance instead of just the terse detail line.
 */
class DeploymentReadinessService
{
    /**
     * Platform/infra-level gaps — relevant to whoever is standing up this
     * SpiderNetOS instance, not any one tenant. No "ask Hannah" forwarding
     * here: these need a human editing .env / infra, not a chat answer.
     *
     * @return list<array<string, mixed>>
     */
    public function platformChecks(): array
    {
        return [
            $this->check(
                key: 'dodo_payments',
                label: 'Dodo Payments configured',
                ok: (bool) config('services.dodo.api_key') && (bool) config('services.dodo.webhook_secret'),
                detail: 'Set DODO_API_KEY and DODO_WEBHOOK_SECRET so pack purchases can complete.',
                docLink: 'docs/feature-packs/SPEC.md#4a-purchase--entitlement-gating',
            ),
            $this->check(
                key: 'dodo_products',
                label: 'Dodo product mapped for sales-crm',
                ok: (bool) config('services.dodo.products.sales-crm'),
                detail: 'Set DODO_PRODUCT_SALES_CRM to the Dodo product id for the Lead-to-Sale Funnel bundle.',
            ),
            $this->check(
                key: 'internal_key',
                label: 'Backend-internal key configured',
                ok: (bool) config('services.internal.key'),
                detail: 'Set BACKEND_INTERNAL_KEY — Python intelligence workers use it to call /api/internal/* (lead scoring, message sends, sequence enrollment).',
            ),
            $this->check(
                key: 'whatsapp_provider',
                label: 'Twilio credentials configured',
                ok: (bool) config('telephony.providers.twilio.sid') && (bool) config('telephony.providers.twilio.auth_token'),
                detail: 'Set TWILIO_SID and TWILIO_AUTH_TOKEN — used for both voice and the funnel\'s WhatsApp channel.',
            ),
            $this->check(
                key: 'database_driver',
                label: 'Postgres + pgvector',
                ok: DB::connection()->getDriverName() === 'pgsql',
                detail: 'DB_CONNECTION must be pgsql with the pgvector extension installed — the memory graph and Feature test suite both require it.',
                warningOnly: true,
            ),
            $this->check(
                key: 'redis',
                label: 'Redis reachable',
                ok: $this->pingRedis(),
                detail: 'Agent dispatch (Python intelligence workers) and feature flags read/write Redis — without it, packs install but agents never activate.',
            ),
            $this->check(
                key: 'mail_driver',
                label: 'Outbound mail configured',
                ok: ! in_array(config('mail.default'), [null, '', 'log', 'array'], true),
                detail: 'MAIL_MAILER is log/array — fine for dev, but the funnel\'s email channel needs a real driver (ses/smtp/mailgun) in production.',
                warningOnly: true,
            ),
        ];
    }

    /**
     * Tenant-scoped gaps for a specific tenant's funnel go-live. Each
     * "missing" item is also raised as an awareness_items row (Priestley
     * Awareness A) so it shows up in /operating alongside every other
     * open item, rather than living only in this one-off panel.
     *
     * @return list<array<string, mixed>>
     */
    public function tenantChecks(string $tenantId): array
    {
        $checks = [
            $this->check(
                key: 'sales_crm_entitlement',
                label: 'Lead-to-Sale Funnel purchased',
                ok: PackEntitlement::forTenant($tenantId)->where('pack_id', 'sales-crm')->active()->exists(),
                detail: 'No active entitlement for sales-crm yet.',
                askHannah: 'How do I purchase the Lead-to-Sale Funnel bundle for my account?',
            ),
            $this->check(
                key: 'whatsapp_number',
                label: 'WhatsApp number provisioned',
                ok: MessagingNumber::forTenant($tenantId)->where('channel', 'whatsapp')->where('is_active', true)->exists(),
                detail: 'No active WhatsApp number for this tenant — outbound WhatsApp sends will fail until one is provisioned.',
                askHannah: 'How do I get a WhatsApp number provisioned for my Lead-to-Sale Funnel?',
            ),
            $this->check(
                key: 'funnel_live',
                label: 'Funnel is live',
                ok: DB::table('funnel_setups')->where('tenant_id', $tenantId)->where('status', 'live')->exists(),
                detail: 'The discovery interview + script approval hasn\'t been completed yet.',
                askHannah: 'What do I need to do to get my Lead-to-Sale Funnel live?',
            ),
        ];

        $this->raiseAwarenessForGaps($tenantId, $checks);

        return $checks;
    }

    private function pingRedis(): bool
    {
        try {
            Redis::ping();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function raiseAwarenessForGaps(string $tenantId, array $checks): void
    {
        foreach ($checks as $c) {
            if ($c['status'] !== 'missing') {
                continue;
            }

            $alreadyOpen = DB::table('awareness_items')
                ->where('tenant_id', $tenantId)
                ->where('title', $c['label'])
                ->whereIn('status', ['open', 'acknowledged'])
                ->exists();

            if ($alreadyOpen) {
                continue;
            }

            DB::table('awareness_items')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $tenantId,
                'source' => 'agent',
                'title' => $c['label'],
                'detail' => $c['detail'],
                'severity' => 'warning',
                'status' => 'open',
                'raised_by' => 'hannah_readiness_check',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function check(string $key, string $label, bool $ok, string $detail, ?string $askHannah = null, ?string $docLink = null, bool $warningOnly = false): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $ok ? 'ok' : ($warningOnly ? 'warning' : 'missing'),
            'detail' => $ok ? null : $detail,
            'ask_hannah_prompt' => $ok ? null : $askHannah,
            'doc_link' => $docLink,
        ];
    }
}
