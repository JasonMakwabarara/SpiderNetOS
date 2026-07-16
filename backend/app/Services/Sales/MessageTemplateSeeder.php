<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\MessageTemplate;
use App\Models\SalesScript;

/**
 * Renders an approved sales script into message_templates rows keyed to
 * match packages/feature-packs/sales-crm/flows/nurture-sequence.yaml's
 * template_key values, so ProcessSequenceStepsJob has real content to send.
 */
class MessageTemplateSeeder
{
    private const SEQUENCE_KEYS = [
        'nurture.step1.opener',
        'nurture.step2.check_in',
        'nurture.step3.proof',
        'nurture.step4.offer',
        'nurture.step5.breakup',
    ];

    public function seedFromScript(string $tenantId, SalesScript $script): void
    {
        foreach (['email', 'whatsapp'] as $channel) {
            $content = $script->content[$channel] ?? [];

            MessageTemplate::updateOrCreate(
                ['tenant_id' => $tenantId, 'channel' => $channel, 'key' => 'welcome'],
                [
                    'pack_id' => 'sales-crm',
                    'subject' => $channel === 'email' ? "Thanks for reaching out, {{lead.first_name}}" : null,
                    'body' => $content['opener'] ?? 'Thanks for reaching out — we\'ll be in touch shortly.',
                    'status' => 'active',
                ],
            );

            $followups = array_values($content['followups'] ?? []);
            foreach (self::SEQUENCE_KEYS as $i => $key) {
                $body = $followups[$i] ?? $content['close'] ?? 'Following up — still worth exploring this together?';

                MessageTemplate::updateOrCreate(
                    ['tenant_id' => $tenantId, 'channel' => $channel, 'key' => $key],
                    [
                        'pack_id' => 'sales-crm',
                        'subject' => $channel === 'email' ? 'Following up, {{lead.first_name}}' : null,
                        'body' => $body,
                        'status' => 'active',
                    ],
                );
            }
        }
    }
}
