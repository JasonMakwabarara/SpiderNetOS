<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use App\Models\MessageTemplate;

/**
 * Default partner-outreach templates. Seeded once per tenant (existing rows
 * are left alone so operator edits survive), keyed to settings.outreach.sequence.
 */
class OutreachTemplateSeeder
{
    public const PACK_ID = 'partner-outreach';

    public const EMAIL_KEYS = ['partner.invite', 'partner.nudge', 'partner.last_call'];

    public const DM_KEYS = ['partner.dm_invite', 'partner.dm_followup'];

    /** @return int templates created */
    public function seed(string $tenantId, bool $overwrite = false): int
    {
        $created = 0;

        foreach ($this->defaults() as [$channel, $key, $subject, $body]) {
            $attrs = ['tenant_id' => $tenantId, 'channel' => $channel, 'key' => $key];
            $values = ['pack_id' => self::PACK_ID, 'subject' => $subject, 'body' => $body, 'status' => 'active'];

            if ($overwrite) {
                MessageTemplate::updateOrCreate($attrs, $values);
                $created++;
            } elseif (MessageTemplate::where($attrs)->doesntExist()) {
                MessageTemplate::create($attrs + $values);
                $created++;
            }
        }

        return $created;
    }

    /** @return list<array{0: string, 1: string, 2: ?string, 3: string}> */
    private function defaults(): array
    {
        $offer = "- {{program.commission_pct}}% recurring commission for {{program.months}} months on every customer you refer\n"
            ."- {{program.cookie_days}}-day cookie, {{program.min_payout_usd}} USD minimum payout, paid through {{program.portal_name}}\n"
            .'- Creator assets and a personal link so you can start the same day';

        $signoff = "{{sender.name}}\n{{program.brand}} Partnerships";

        return [
            ['email', 'partner.invite',
                'Partnering with {{program.brand}}: {{program.commission_pct}}% recurring for your audience',
                "Hi {{prospect.first_name}},\n\n"
                .'I run partnerships at {{program.brand}}, an AI tool that turns one brief into a finished ad campaign (script, video, voiceover, captions). '
                ."I found your {{prospect.platform_label}} content while looking for creators whose audience runs marketing for small businesses, and it is a strong fit.\n\n"
                ."We are opening our affiliate program to a small group of creators:\n\n{$offer}\n\n"
                ."Join here: {{links.join}}\nTerms: {{links.terms}}\n\n"
                ."If you have questions, just reply to this email; I answer personally.\n\n{$signoff}",
            ],
            ['email', 'partner.nudge',
                'Re: Partnering with {{program.brand}}',
                "Hi {{prospect.first_name}},\n\n"
                .'Quick follow-up in case my last note got buried. The short version: {{program.commission_pct}}% recurring for {{program.months}} months on every customer you send to {{program.brand}}, '
                ."with a {{program.cookie_days}}-day cookie and payouts through {{program.portal_name}}.\n\n"
                ."Join here: {{links.join}}\n\nHappy to answer anything by reply.\n\n{$signoff}",
            ],
            ['email', 'partner.last_call',
                'Last note from {{program.brand}}',
                "Hi {{prospect.first_name}},\n\n"
                ."I will not keep nudging. If promoting {{program.brand}} to your {{prospect.platform_label}} audience is ever interesting, the door stays open: {{links.join}}\n\n"
                ."Thanks for reading, and good luck with the channel.\n\n{$signoff}",
            ],
            ['manual_dm', 'partner.dm_invite', null,
                'Hi {{prospect.first_name}}! I run partnerships at {{program.brand}} (AI ad campaigns from one brief). '
                .'We pay creators {{program.commission_pct}}% recurring for {{program.months}} months on every customer they refer, and your {{prospect.platform_label}} content is a great fit. '
                .'Details and your join link: {{links.join}} Questions? Email {{sender.email}}',
            ],
            ['manual_dm', 'partner.dm_followup', null,
                'Hi {{prospect.first_name}}, following up on the {{program.brand}} affiliate invite: {{program.commission_pct}}% recurring for {{program.months}} months. '
                .'Join link: {{links.join}} or email {{sender.email}} with any questions.',
            ],
        ];
    }
}
