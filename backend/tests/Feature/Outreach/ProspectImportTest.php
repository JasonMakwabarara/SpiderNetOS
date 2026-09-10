<?php

declare(strict_types=1);

namespace Tests\Feature\Outreach;

use App\Models\ConsentRecord;
use App\Models\Lead;
use App\Models\PartnerProspect;

class ProspectImportTest extends OutreachTestCase
{
    public function test_affonso_export_imports_dedupes_and_routes_by_contact(): void
    {
        $report = $this->import([
            ['name' => 'Mike Futia', 'domain' => 'https://www.tiktok.com/@mike_futia', 'primary' => 'https://www.tiktok.com/@mike_futia/video/1'],
            // Same creator, different URL spelling + a second post: duplicate in-file.
            ['name' => 'Mike Futia', 'domain' => 'http://m.tiktok.com/@Mike_Futia/', 'primary' => 'https://www.tiktok.com/@mike_futia/video/2'],
            ['name' => 'Social Media Examiner', 'domain' => 'https://www.facebook.com/smexaminer/', 'primary' => 'https://www.facebook.com/smexaminer/posts/1', 'email' => 'partners@smexaminer.test'],
            ['name' => 'Broken row', 'domain' => '', 'primary' => ''],
        ]);

        $this->assertSame(4, $report->rows);
        $this->assertSame(2, $report->created);
        $this->assertSame(1, $report->duplicates);
        $this->assertSame(1, $report->invalid);
        $this->assertSame(1, $report->withEmail);

        $mike = PartnerProspect::forTenant($this->tenant->id)->where('handle', 'mike_futia')->firstOrFail();
        $this->assertSame('tiktok', $mike->platform);
        $this->assertSame(PartnerProspect::STATUS_NEEDS_EMAIL, $mike->status);
        $this->assertSame(22, strlen($mike->invite_token));
        $this->assertSame('import', $mike->lead->source);
        $this->assertNull($mike->lead->email);
        $this->assertFalse((bool) $mike->lead->consent['email_opt_in']);

        $sme = PartnerProspect::forTenant($this->tenant->id)->where('handle', 'smexaminer')->firstOrFail();
        $this->assertSame(PartnerProspect::STATUS_READY, $sme->status);
        $this->assertNotNull($sme->next_send_at);
        $this->assertSame('finder', $sme->email_source);
        $this->assertSame('partners@smexaminer.test', $sme->lead->email);
        $this->assertTrue((bool) $sme->lead->consent['email_opt_in']);
        $this->assertDatabaseHas('consent_records', ['tenant_id' => $this->tenant->id, 'subject' => 'partners@smexaminer.test', 'channel' => 'email', 'status' => 'granted']);

        $this->assertSame(2, Lead::forTenant($this->tenant->id)->count());
    }

    public function test_reimport_refreshes_urls_but_never_touches_contact_or_status(): void
    {
        $this->import([['name' => 'Neil', 'domain' => 'https://www.tiktok.com/@neilpatel', 'primary' => 'https://www.tiktok.com/@neilpatel/video/1']]);
        $prospect = PartnerProspect::forTenant($this->tenant->id)->firstOrFail();
        $prospect->forceFill(['status' => PartnerProspect::STATUS_INVITED, 'sequence_step' => 1])->save();
        $prospect->lead->update(['email' => 'neil@example.test']);

        $report = $this->import([['name' => 'Neil Patel', 'domain' => 'https://tiktok.com/@NeilPatel/', 'primary' => 'https://www.tiktok.com/@neilpatel/video/1', 'all' => 'https://www.tiktok.com/@neilpatel/video/1; https://www.tiktok.com/@neilpatel/video/9']]);

        $this->assertSame(1, $report->updated);
        $this->assertSame(0, $report->created);
        $prospect->refresh();
        $this->assertSame(PartnerProspect::STATUS_INVITED, $prospect->status);
        $this->assertSame(1, $prospect->sequence_step);
        $this->assertSame('neil@example.test', $prospect->lead->email);
        $this->assertContains('https://www.tiktok.com/@neilpatel/video/9', $prospect->all_urls);
        $this->assertSame(1, PartnerProspect::forTenant($this->tenant->id)->count());
    }

    public function test_unusable_finder_emails_are_rejected_and_recorded(): void
    {
        $this->import([['name' => 'Bot', 'domain' => 'https://www.tiktok.com/@botty', 'primary' => 'x', 'email' => 'noreply@brand.test']]);

        $p = PartnerProspect::forTenant($this->tenant->id)->firstOrFail();
        $this->assertSame(PartnerProspect::STATUS_NEEDS_EMAIL, $p->status);
        $this->assertNull($p->lead->email);
        $this->assertSame(['noreply@brand.test'], $p->source_meta['emails']);
        $this->assertSame(0, ConsentRecord::forTenant($this->tenant->id)->count());
    }

    public function test_dry_run_and_artisan_command(): void
    {
        $report = $this->import([['name' => 'A', 'domain' => 'https://www.tiktok.com/@a', 'primary' => 'x']], true);
        $this->assertSame(1, $report->created);
        $this->assertSame(0, PartnerProspect::forTenant($this->tenant->id)->count());

        $path = $this->csvFile([['name' => 'A', 'domain' => 'https://www.tiktok.com/@a', 'primary' => 'x']]);
        $this->artisan('outreach:import', ['tenant' => $this->tenant->slug, 'path' => $path])
            ->expectsOutputToContain('1 created')
            ->assertSuccessful();
        $this->assertSame(1, PartnerProspect::forTenant($this->tenant->id)->count());

        $this->artisan('outreach:import', ['tenant' => 'nope', 'path' => $path])->assertFailed();
    }
}
