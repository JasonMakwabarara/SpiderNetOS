<?php

declare(strict_types=1);

namespace Tests\Feature\Revisions;

use App\Jobs\DistilCorrectionsJob;
use App\Models\ArtifactRevision;
use App\Models\BrainProposal;
use App\Models\Tenant;
use App\Services\Founder\BrainWriter;
use App\Services\Revisions\RevisionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** artifact_revisions persistence + the weekly distillation (plan D8 #1). */
class RevisionLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(), 'name' => 'Ledger Co', 'slug' => 'ledger-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
    }

    public function test_record_stores_original_edited_distance_categories_and_why(): void
    {
        $tenant = $this->tenant();
        $recorder = app(RevisionRecorder::class);

        $revision = $recorder->record(
            (string) $tenant->id,
            ArtifactRevision::SUBJECT_CONVERSATION_MESSAGE,
            (string) Str::uuid(),
            'Hi Sam, we help agencies book more calls. See https://cal.example.com/sam — 20% more replies. Worth a chat?',
            'Hi Sam, we help agencies book more calls. See https://cal.example.com/team — 20% more replies. Worth a chat?',
            (string) Str::uuid(),
            ['why' => 'wrong link', 'action' => 'edit', 'skill_slug' => 'cold-email-drafting', 'channel' => 'email'],
        );

        $this->assertInstanceOf(ArtifactRevision::class, $revision);
        $this->assertDatabaseHas('artifact_revisions', [
            'id' => $revision->id,
            'tenant_id' => $tenant->id,
            'subject_type' => 'conversation_message',
            'skill_slug' => 'cold-email-drafting',
            'why' => 'wrong link',
            'action' => 'edit',
        ]);
        $this->assertSame(['links'], $revision->categories);
        $this->assertGreaterThan(0, $revision->distance);
        $this->assertTrue($revision->isCleanDraft());
        $this->assertSame(['channel' => 'email'], $revision->meta);
        $this->assertStringContainsString('/sam', $revision->original_body);
        $this->assertStringContainsString('/team', $revision->edited_body);
    }

    public function test_clean_share_counts_only_revisions_under_the_threshold(): void
    {
        $tenant = $this->tenant();
        $recorder = app(RevisionRecorder::class);
        $base = 'We help agencies book ten more qualified calls a month without hiring an SDR. Worth a quick chat?';

        $recorder->record((string) $tenant->id, 'agent_artifact', (string) Str::uuid(), $base, $base.'!', null, ['skill_slug' => 'x']);
        $recorder->record((string) $tenant->id, 'agent_artifact', (string) Str::uuid(), $base, 'Totally rewritten from scratch, nothing in common.', null, ['skill_slug' => 'x']);

        $this->assertSame(0.5, $recorder->cleanShare((string) $tenant->id, 'x'));
        $this->assertNull($recorder->cleanShare((string) $tenant->id, 'never-edited'));
    }

    public function test_distil_job_opens_one_proposal_for_recurring_corrections(): void
    {
        $tenant = $this->tenant();
        $recorder = app(RevisionRecorder::class);
        $long = 'Hi Sam, I hope this finds you well. We help agencies book ten more qualified calls a month without hiring an SDR, and our clients typically see results inside the first six weeks. Would you be open to a fifteen-minute chat next week to see if this fits?';
        $short = 'Hi Sam, we help agencies book ten more calls a month without an SDR. Open to a 15-minute chat?';

        foreach (range(1, 4) as $i) {
            $recorder->record((string) $tenant->id, 'agent_artifact', (string) Str::uuid(), $long, $short, null, ['skill_slug' => 'cold-email-drafting', 'why' => 'too long']);
        }

        $rules = (new DistilCorrectionsJob)->distil((string) $tenant->id, now()->subDays(7), app(BrainWriter::class));

        $this->assertNotEmpty($rules);
        $this->assertStringContainsString('shorten', $rules[0]);
        $this->assertStringContainsString('4 of 4', $rules[0]);

        $proposal = BrainProposal::forTenant((string) $tenant->id)->where('path', DistilCorrectionsJob::PATH)->first();
        $this->assertNotNull($proposal);
        $this->assertSame('DistilCorrectionsJob', $proposal->proposed_by_ref);
        $this->assertStringContainsString('## '.DistilCorrectionsJob::SECTION, $proposal->proposed_content);
        $this->assertStringContainsString('too long', $proposal->rationale);

        // Second run inside the window does not duplicate the proposal.
        (new DistilCorrectionsJob)->distil((string) $tenant->id, now()->subDays(7), app(BrainWriter::class));
        $this->assertSame(1, BrainProposal::forTenant((string) $tenant->id)->count());
    }

    public function test_brain_writer_replaces_or_appends_sections(): void
    {
        $doc = "# Me\n\n## Role\n\nFounder.\n\n## What I always edit\n\n- old rule\n\n## Never say\n\nNothing.\n";
        $updated = BrainWriter::replaceSection($doc, 'What I always edit', '- new rule');

        $this->assertStringContainsString("## What I always edit\n\n- new rule\n\n## Never say", $updated);
        $this->assertStringNotContainsString('old rule', $updated);
        $this->assertStringContainsString('Founder.', $updated);

        $appended = BrainWriter::replaceSection("# Me\n", 'Calendar and meetings', 'cal.example.com/me');
        $this->assertStringEndsWith("## Calendar and meetings\n\ncal.example.com/me\n", $appended);
    }
}
