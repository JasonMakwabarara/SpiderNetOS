<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ArtifactRevision;
use App\Models\BrainProposal;
use App\Services\Founder\BrainWriter;
use App\Services\Revisions\RevisionRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Every edit is a lesson (plan D8 #1): once a week, read the edit ledger
 * (artifact_revisions), find the corrections the founder makes over and
 * over ("you shorten the opener in 5 of 6 drafts"), and open a brain
 * proposal for people/user.md — section "What I always edit" — so the
 * rule reaches every skill's PEOPLE block once approved. Deterministic:
 * a category must appear in at least MIN_SHARE of MIN_SAMPLES+ edits.
 */
class DistilCorrectionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 120;

    public const PATH = 'people/user.md';

    public const SECTION = 'What I always edit';

    public const MIN_SAMPLES = 3;

    public const MIN_SHARE = 0.6;

    public function __construct(private readonly int $windowDays = 7) {}

    public function handle(BrainWriter $writer): void
    {
        if (! Schema::hasTable('artifact_revisions') || ! Schema::hasTable('brain_proposals')) {
            return;
        }

        $since = now()->subDays($this->windowDays);
        $tenantIds = ArtifactRevision::where('created_at', '>=', $since)->distinct()->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            try {
                $this->distil((string) $tenantId, $since, $writer);
            } catch (\Throwable $e) {
                Log::error('revisions.distil.failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @return list<string> the rules proposed (empty when nothing recurred)
     */
    public function distil(string $tenantId, \DateTimeInterface $since, BrainWriter $writer): array
    {
        $revisions = ArtifactRevision::forTenant($tenantId)->where('created_at', '>=', $since)
            ->where('distance', '>=', RevisionRecorder::CLEAN_THRESHOLD)->get();

        $rules = self::rulesFor($revisions->all());
        if ($rules === []) {
            return [];
        }

        $alreadyOpen = BrainProposal::forTenant($tenantId)->open()->where('path', self::PATH)
            ->where('proposed_by_ref', 'DistilCorrectionsJob')->where('created_at', '>=', $since)->exists();
        if ($alreadyOpen) {
            return $rules;
        }

        $current = $writer->read($tenantId, self::PATH) ?? '';
        $body = implode("\n", array_map(fn (string $r): string => '- '.$r, $rules));
        $proposed = BrainWriter::replaceSection($current, self::SECTION, $body);

        $file = Schema::hasTable('brain_files')
            ? DB::table('brain_files')->where('tenant_id', $tenantId)->where('path', self::PATH)->first(['id', 'version'])
            : null;

        BrainProposal::create([
            'tenant_id' => $tenantId,
            'path' => self::PATH,
            'brain_file_id' => $file?->id,
            'base_version' => $file?->version,
            'proposed_content' => $proposed,
            'rationale' => 'Distilled from '.$revisions->count().' edits in the last '.$this->windowDays.' days: '.implode('; ', $rules),
            'status' => BrainProposal::STATUS_PENDING,
            'proposed_by_type' => BrainProposal::BY_SYSTEM,
            'proposed_by_ref' => 'DistilCorrectionsJob',
        ]);

        Log::info('revisions.distil.proposed', ['tenant_id' => $tenantId, 'rules' => count($rules)]);

        return $rules;
    }

    /**
     * Pure: turn a batch of revisions into human rules, grouped by skill.
     *
     * @param  list<ArtifactRevision>  $revisions
     * @return list<string>
     */
    public static function rulesFor(array $revisions): array
    {
        $groups = [];
        foreach ($revisions as $r) {
            $groups[$r->skill_slug ?: 'general'][] = $r;
        }

        $rules = [];
        foreach ($groups as $skill => $items) {
            $n = count($items);
            if ($n < self::MIN_SAMPLES) {
                continue;
            }
            $label = $skill === 'general' ? 'drafts' : Str::headline($skill).' drafts';

            $counts = [];
            $shorter = 0;
            $longer = 0;
            $whys = [];
            foreach ($items as $r) {
                foreach ((array) $r->categories as $c) {
                    $counts[$c] = ($counts[$c] ?? 0) + 1;
                }
                $wa = str_word_count((string) $r->original_body);
                $wb = str_word_count((string) $r->edited_body);
                if ($wb < $wa) {
                    $shorter++;
                } elseif ($wb > $wa) {
                    $longer++;
                }
                if ($r->why) {
                    $whys[$r->why] = ($whys[$r->why] ?? 0) + 1;
                }
            }

            foreach (['length', 'tone', 'ask', 'numbers', 'links', 'facts'] as $category) {
                $k = $counts[$category] ?? 0;
                if ($k / $n < self::MIN_SHARE) {
                    continue;
                }
                $rules[] = match ($category) {
                    'length' => $shorter >= $longer
                        ? "You shorten {$label} in {$k} of {$n} edits — keep them tighter (target the edited length, not the drafted one)."
                        : "You lengthen {$label} in {$k} of {$n} edits — the drafts are missing context you always add.",
                    'tone' => "You rewrite the tone of {$label} in {$k} of {$n} edits — the edited versions are the voice to copy.",
                    'ask' => "You change the ask/CTA in {$label} in {$k} of {$n} edits — one clear ask, phrased the way you phrase it.",
                    'numbers' => "You correct numbers in {$label} in {$k} of {$n} edits — never state a figure that is not in the brain.",
                    'links' => "You change links in {$label} in {$k} of {$n} edits — use only the approved links in offer/offer.md.",
                    'facts' => "You correct facts, names or claims in {$label} in {$k} of {$n} edits — cite the brain, do not infer.",
                };
            }

            if ($whys !== []) {
                arsort($whys);
                $topWhy = array_key_first($whys);
                if ($whys[$topWhy] >= 2) {
                    $rules[] = "Most common reason given for editing {$label}: \"{$topWhy}\" ({$whys[$topWhy]}×).";
                }
            }
        }

        return $rules;
    }
}
