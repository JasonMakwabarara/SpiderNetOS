<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Services\Agents\ApplicationPayload;
use App\Services\Agents\Exceptions\BundleIntegrityException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Every child a sequence's steps name must belong to it. Each fault is
 * induced on its own, so each check is shown to be the one that refuses —
 * a foreign child fails several checks at once and would hide which of them
 * actually works.
 */
class BundleOwnershipTest extends AgentsTestCase
{
    public function test_an_intact_bundle_builds_and_names_every_member_once(): void
    {
        [$sequence, $children] = $this->bundle();

        $payload = ApplicationPayload::for($sequence);

        $this->assertSame($children->pluck('id')->sort()->values()->all(), collect($payload['steps'])->pluck('artifact_id')->sort()->values()->all());
    }

    public function test_a_step_naming_an_artifact_outside_the_bundle_is_refused(): void
    {
        [$sequence] = $this->bundle();
        $foreign = $this->foreignChild();

        $this->tamper($sequence, fn (array $meta) => $this->withStep($meta, 0, (string) $foreign->id));

        $this->assertRefused($sequence, 'names an artifact outside the bundle');
    }

    public function test_a_member_from_another_run_is_refused_even_when_it_points_back(): void
    {
        [$sequence] = $this->bundle();
        $foreign = $this->foreignChild();
        // Everything else says it belongs: listed as a member, and pointing
        // back at this sequence. Only its run gives it away.
        $foreign->forceFill(['meta' => ['sequence_id' => (string) $sequence->id] + (array) $foreign->meta])->save();

        $this->tamper($sequence, function (array $meta) use ($foreign): array {
            $replaced = (string) $meta['steps'][0]['artifact_id'];

            return $this->asMember($this->withStep($meta, 0, (string) $foreign->id), $replaced, (string) $foreign->id);
        });

        $this->assertRefused($sequence, 'names an artifact from another run');
    }

    public function test_a_member_of_the_wrong_kind_is_refused(): void
    {
        [$sequence, $children] = $this->bundle();
        $child = $children->first();
        $child->forceFill(['kind' => AgentArtifact::KIND_DRAFT_REPLY])->save();

        $this->assertRefused($sequence, 'names a draft_reply, not a draft email');
    }

    public function test_a_member_that_points_at_another_sequence_is_refused(): void
    {
        [$sequence, $children] = $this->bundle();
        $child = $children->first();
        $child->forceFill(['meta' => ['sequence_id' => (string) Str::uuid()] + (array) $child->meta])->save();

        $this->assertRefused($sequence, 'belongs to another sequence');
    }

    public function test_two_steps_naming_one_artifact_are_refused(): void
    {
        [$sequence, $children] = $this->bundle();

        $this->tamper($sequence, fn (array $meta) => $this->withStep($meta, 1, (string) $children->first(fn ($c) => (int) $c->meta['n'] === 1)->id));

        $this->assertRefused($sequence, 'names an artifact another step already uses');
    }

    public function test_a_member_no_step_uses_is_refused(): void
    {
        [$sequence] = $this->bundle();
        $extra = $this->foreignChild();

        $this->tamper($sequence, function (array $meta) use ($extra): array {
            $meta['artifact_ids'][] = (string) $extra->id;

            return $meta;
        });

        $this->assertRefused($sequence, 'Bundle members that no step uses');
    }

    // ------------------------------------------------------------------ //

    /** @return array{0: AgentArtifact, 1: Collection<int, AgentArtifact>} */
    private function bundle(): array
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());
        $run = $this->startRun();
        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);

        return [
            AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_SEQUENCE)->sole(),
            AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_EMAIL)->get(),
        ];
    }

    private function foreignChild(): AgentArtifact
    {
        $this->model($this->validSequenceCompletion('Autumn Push'));
        $other = $this->startRun(array_merge($this->defaultInputs(), ['campaign' => 'Autumn Push']), 'other-'.uniqid());

        return AgentArtifact::where('run_id', $other->id)->where('kind', AgentArtifact::KIND_DRAFT_EMAIL)->firstOrFail();
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change */
    private function tamper(AgentArtifact $sequence, callable $change): void
    {
        $sequence->forceFill(['meta' => $change((array) $sequence->fresh()->meta)])->save();
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function withStep(array $meta, int $index, string $artifactId): array
    {
        $meta['steps'][$index]['artifact_id'] = $artifactId;

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function asMember(array $meta, string $replacing, string $with): array
    {
        $meta['artifact_ids'] = array_values(array_map(fn ($id) => $id === $replacing ? $with : $id, $meta['artifact_ids']));

        return $meta;
    }

    private function assertRefused(AgentArtifact $sequence, string $because): void
    {
        try {
            ApplicationPayload::for($sequence);
            $this->fail("the bundle built although it {$because}");
        } catch (BundleIntegrityException $e) {
            $this->assertStringContainsString($because, $e->getMessage());
        }
    }
}
