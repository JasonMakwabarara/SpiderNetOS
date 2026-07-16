<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * First real Mailable in the codebase — post-call summaries
 * (ProcessVoiceCallSummary) still use Mail::raw(). Renders a tenant's
 * branding (see cockpit onboarding StepBranding.vue, persisted to
 * tenants.onboarding['branding'], not tenants.settings) around the body.
 */
class BrandedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $subjectLine,
        public readonly string $bodyText,
    ) {}

    public function build(): self
    {
        $branding = (array) ($this->tenant->onboarding['branding'] ?? []);

        return $this->subject($this->subjectLine)
            ->view('emails.layouts.branded')
            ->with([
                'bodyText' => $this->bodyText,
                'primaryColor' => $branding['primary_color'] ?? '#00E5C8',
                'logoUrl' => $branding['logo_url'] ?? null,
                'businessName' => $this->tenant->name,
            ]);
    }
}
