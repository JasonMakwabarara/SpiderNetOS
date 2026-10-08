<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Services\Agents\ApplicationPayload;

/**
 * What the version hash must and must not respond to, stated as behaviour of
 * the real payload read back from the database.
 *
 * The canonical form is CanonicalJson: object keys sorted recursively, list
 * order kept, fixed encoding flags, values and types preserved. Text is
 * hashed as it will be applied — the payload is what the applier writes, so
 * whatever normalisation it carries (the step subject and body are trimmed,
 * as before this commit) is applied as well as hashed, never one without the
 * other.
 */
class ApplicationPayloadHashTest extends AgentsTestCase
{
    /**
     * The same rows hash to the same constant on every database this runs
     * on — local runs use SQLite, CI uses Postgres, whose `jsonb` reorders the
     * keys of the stored meta — and the canonical form itself is pinned: any
     * change to it changes this constant.
     *
     * What this does not show is that the key sort is what makes the two
     * databases agree. ApplicationPayload emits its keys in a fixed order and
     * reads only values out of `jsonb`, so with the sort removed both
     * databases still agree, on a different constant. The sort is load-bearing
     * where a structure comes back from `jsonb` as stored — the approval's
     * `context.payload` — and VersionBindingTest's first test is the one that
     * fails on Postgres without it.
     */
    public function test_fixed_rows_hash_to_one_constant_on_any_database(): void
    {
        $tenant = '0192f000-0000-7000-8000-000000000001';
        $sequenceId = '0192f000-0000-7000-8000-00000000000a';
        $emailId = '0192f000-0000-7000-8000-00000000000b';
        AgentArtifact::forceCreate([
            'id' => $emailId, 'tenant_id' => $tenant, 'kind' => AgentArtifact::KIND_DRAFT_EMAIL, 'status' => AgentArtifact::STATUS_DRAFT,
            'title' => 'Step 1', 'content' => "Subject: Hello\n\nA body.",
            'meta' => ['sequence_id' => $sequenceId, 'n' => 1, 'subject' => 'Hello', 'body' => 'A body.'],
        ]);
        AgentArtifact::forceCreate([
            'id' => $sequenceId, 'tenant_id' => $tenant, 'kind' => AgentArtifact::KIND_DRAFT_SEQUENCE, 'status' => AgentArtifact::STATUS_DRAFT,
            'title' => 'Fixed', 'content' => '# Fixed',
            // Keys deliberately out of order at every level.
            'meta' => [
                'steps' => [['variants' => [['body' => 'A body.', 'subject' => 'Hello', 'key' => 'a'], ['subject' => 'Hi', 'key' => 'b', 'body' => 'A body.']], 'n' => 1, 'artifact_id' => $emailId, 'body' => 'A body.', 'subject' => 'Hello', 'delay_days' => 0]],
                'pack_id' => 'sales-crm', 'channel' => 'email', 'campaign' => 'Fixed', 'artifact_ids' => [$emailId], 'campaign_key' => 'fixed',
            ],
        ]);

        $this->assertSame(
            'sha256:1e65aece7c3ee4e14e1737bf52cd5b112a247633d4d7fc017a5bdab9ba72a96c',
            ApplicationPayload::hash(ApplicationPayload::for(AgentArtifact::findOrFail($sequenceId))),
        );
    }

    public function test_the_hash_moves_with_what_would_be_applied(): void
    {
        [$run] = $this->bundle();
        $sequence = $this->sequence($run);
        $before = $this->versionOf($sequence);

        $cases = [
            'a step body' => fn () => $this->email($run, 1)->forceFill(['content' => "Subject: Leads are going cold in your inbox\n\nA different body."])->save(),
            'a step subject' => fn () => $this->email($run, 2)->forceFill(['content' => "Subject: Another subject\n\n".$this->bodyOf($this->email($run, 2))])->save(),
            'the destination' => fn () => $this->meta($sequence, fn (array $m) => ['campaign_key' => 'somewhere-else'] + $m),
            'a variant of its own' => fn () => $this->meta($sequence, function (array $m): array {
                $m['steps'][0]['variants'][1]['subject'] = 'A different b subject';

                return $m;
            }),
            'the order of the steps' => fn () => $this->meta($sequence, function (array $m): array {
                $m['steps'] = array_reverse($m['steps']);

                return $m;
            }),
        ];

        foreach ($cases as $change => $apply) {
            $apply();
            $after = $this->versionOf($sequence);
            $this->assertNotSame($before, $after, "changing {$change} must change the version");
            $before = $after;
        }
    }

    /** Bookkeeping that is not applied does not change what was approved. */
    public function test_the_hash_ignores_what_would_not_be_applied(): void
    {
        [$run] = $this->bundle();
        $sequence = $this->sequence($run);
        $before = $this->versionOf($sequence);

        $email = $this->email($run, 1);
        $email->forceFill([
            'title' => 'Renamed for the reviewer',
            'meta' => ['edited_at' => now()->toIso8601String(), 'edit_count' => 3, 'note' => 'checked'] + (array) $email->meta,
        ])->save();
        $sequence->forceFill(['title' => 'Also renamed', 'meta' => ['reviewed_note' => 'fine'] + (array) $sequence->fresh()->meta])->save();

        $this->assertSame($before, $this->versionOf($sequence));
    }

    // ------------------------------------------------------------------ //

    /** @return array{0: AgentRun} */
    private function bundle(): array
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());
        $run = $this->startRun();
        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);

        return [$run];
    }

    private function versionOf(AgentArtifact $sequence): string
    {
        return ApplicationPayload::hash(ApplicationPayload::for($sequence));
    }

    private function sequence(AgentRun $run): AgentArtifact
    {
        return AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_SEQUENCE)->sole();
    }

    private function email(AgentRun $run, int $n): AgentArtifact
    {
        return AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_EMAIL)->get()->first(fn ($a) => (int) $a->meta['n'] === $n);
    }

    private function bodyOf(AgentArtifact $email): string
    {
        return (string) preg_replace('/^Subject:.*?\R\R/s', '', (string) $email->content);
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change */
    private function meta(AgentArtifact $sequence, callable $change): void
    {
        $fresh = $sequence->fresh();
        $fresh->forceFill(['meta' => $change((array) $fresh->meta)])->save();
    }
}
