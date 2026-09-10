<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use App\Models\MessageTemplate;
use App\Models\PartnerProspect;
use App\Models\Tenant;
use App\Services\Messaging\MessageDispatchService;
use App\Services\Messaging\TenantMailerFactory;

/**
 * Renders a tenant's outreach templates for one prospect. The merge fields
 * are the only personalisation: a handle and a platform, plus the program
 * facts from settings. No model writes the first touch, because a handle
 * alone would tempt it to invent specifics the creator never said.
 */
class OutreachMessageComposer
{
    private const PLATFORM_LABELS = [
        'tiktok' => 'TikTok', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube',
        'x' => 'X', 'linkedin' => 'LinkedIn', 'web' => 'website', 'other' => 'content',
    ];

    public function __construct(
        private readonly OutreachSettings $settings,
        private readonly TenantMailerFactory $mailers,
        private readonly OutreachTemplateSeeder $seeder,
    ) {}

    /**
     * @return array{subject: ?string, body: string, template_key: string}
     */
    public function compose(Tenant $tenant, PartnerProspect $prospect, string $templateKey, string $channel): array
    {
        $template = $this->template($tenant, $channel, $templateKey);
        $fields = $this->fields($tenant, $prospect);

        return [
            'subject' => $template->subject ? MessageDispatchService::renderFields($template->subject, $fields) : null,
            'body' => MessageDispatchService::renderFields($template->body, $fields),
            'template_key' => $templateKey,
        ];
    }

    /** @return array<string, string> merge fields ({{ key }}) */
    public function fields(Tenant $tenant, PartnerProspect $prospect): array
    {
        $config = $this->settings->for($tenant);
        $program = (array) $config['program'];
        $sender = $this->mailers->senderFor($this->mailers->credentialsFor((string) $tenant->id));

        $name = trim((string) ($prospect->display_name ?: $prospect->handle ?: ''));
        $first = trim(explode(' ', $name)[0]);
        $first = $first !== '' ? $first : 'there';

        return [
            'prospect.first_name' => $first,
            'prospect.name' => $name,
            'prospect.handle' => (string) ($prospect->handle ?? ''),
            'prospect.platform' => (string) $prospect->platform,
            'prospect.platform_label' => self::PLATFORM_LABELS[$prospect->platform] ?? ucfirst((string) $prospect->platform),
            'prospect.profile_url' => (string) $prospect->profile_url,
            'program.brand' => (string) ($program['brand'] ?? ''),
            'program.commission_pct' => (string) ($program['commission_pct'] ?? ''),
            'program.months' => (string) ($program['months'] ?? ''),
            'program.cookie_days' => (string) ($program['cookie_days'] ?? ''),
            'program.min_payout_usd' => (string) ($program['min_payout_usd'] ?? ''),
            'program.hold_days' => (string) ($program['hold_days'] ?? ''),
            'program.terms_url' => (string) ($program['terms_url'] ?? ''),
            'program.portal_name' => (string) ($program['portal_name'] ?? ''),
            'program.operator_legal_name' => (string) ($program['operator_legal_name'] ?? ''),
            'program.postal_address' => (string) ($program['postal_address'] ?? ''),
            'links.join' => $this->joinLink($program, $prospect),
            'links.terms' => (string) ($program['terms_url'] ?? ''),
            'links.unsubscribe' => $this->unsubscribeUrl($prospect),
            'sender.name' => (string) ($sender['name'] ?: (($program['brand'] ?? 'Partnerships').' Partnerships')),
            'sender.email' => (string) $sender['address'],
            'lead.first_name' => $first,
            'lead.name' => $name,
            'lead.email' => (string) ($prospect->lead->email ?? ''),
        ];
    }

    /** The program join link with attribution back to this prospect. */
    public function joinLink(array $program, PartnerProspect $prospect): string
    {
        $url = trim((string) ($program['join_url'] ?? ''));
        if ($url === '') {
            return trim((string) ($program['terms_url'] ?? ''));
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query([
            'utm_source' => 'spidernet_outreach',
            'utm_medium' => (string) $prospect->platform,
            'utm_content' => (string) $prospect->invite_token,
        ]);
    }

    public function unsubscribeUrl(PartnerProspect $prospect): string
    {
        return rtrim((string) config('app.url'), '/').'/api/public/outreach/unsubscribe/'.$prospect->invite_token;
    }

    private function template(Tenant $tenant, string $channel, string $key): MessageTemplate
    {
        $find = fn () => MessageTemplate::forTenant((string) $tenant->id)
            ->where('channel', $channel)->where('key', $key)->where('status', 'active')->first();

        $template = $find();
        if ($template === null) {
            $this->seeder->seed((string) $tenant->id);
            $template = $find();
        }

        if ($template === null) {
            throw new \RuntimeException("No active {$channel} template '{$key}' for tenant {$tenant->id}.");
        }

        return $template;
    }
}
