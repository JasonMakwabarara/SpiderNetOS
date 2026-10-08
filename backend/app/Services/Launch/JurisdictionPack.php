<?php

declare(strict_types=1);

namespace App\Services\Launch;

use App\Services\ComplianceRadar;
use App\Services\Interviews\ArrayAnswerStore;
use App\Services\Interviews\InterviewRunner;
use Illuminate\Support\Carbon;

/**
 * Registration, tax and data-protection obligations for one country, read
 * from packages/feature-packs/business-launch/jurisdictions/<code>.yaml and
 * merged with the ambient five-rule heuristic in ComplianceRadar (plan D7 §5).
 *
 * Deterministic on purpose: item selection is structure + trigger filtering,
 * never an LLM. Items the founder's answers cannot decide yet stay in the list
 * marked `conditional` so nothing silently disappears, and a pack whose
 * `valid_as_of` is older than `review_after_days` raises an awareness item.
 *
 * Nothing here is legal or financial advice; every payload says so.
 */
class JurisdictionPack
{
    public const DISCLAIMER = 'Not legal or financial advice.';

    private const DEFAULT_REVIEW_AFTER_DAYS = 180;

    /** @var array<string, array<string, mixed>> */
    private array $packs = [];

    private ?InterviewRunner $runner = null;

    public function __construct(
        private readonly ComplianceRadar $radar,
    ) {}

    /**
     * Country codes the pack ships (pack.yaml → spec.jurisdictions.codes).
     *
     * @return list<string>
     */
    public function codes(): array
    {
        $pack = $this->reader()->loadPackFile('pack.yaml');
        $codes = (array) data_get($pack, 'spec.jurisdictions.codes', []);
        $codes = array_values(array_filter(array_map('strval', $codes)));

        return $codes ?: ['uk', 'za', 'zw'];
    }

    /**
     * The raw jurisdiction yaml, or [] when the code is unknown.
     *
     * @return array<string, mixed>
     */
    public function load(string $code): array
    {
        $code = strtolower(trim($code));
        if (! preg_match('/^[a-z]{2,8}$/', $code)) {
            return [];
        }
        if (array_key_exists($code, $this->packs)) {
            return $this->packs[$code];
        }

        return $this->packs[$code] = $this->reader()->loadPackFile('jurisdictions/'.$code.'.yaml');
    }

    public function has(string $code): bool
    {
        return $this->load($code) !== [];
    }

    /**
     * The merged checklist for one jurisdiction.
     *
     * @param  array<string, mixed>  $profile  founder answers + ComplianceRadar profile keys
     * @return array{jurisdiction: array<string, mixed>, items: list<array<string, mixed>>, awareness: list<array<string, mixed>>, deadlines: list<array<string, mixed>>, disclaimer: string}|null
     */
    public function checklist(string $code, array $profile = []): ?array
    {
        $data = $this->load($code);
        if ($data === []) {
            return null;
        }

        $facts = $this->facts($profile);
        $today = Carbon::instance(now())->startOfDay();
        $incorporated = $this->date($profile['incorporated_on'] ?? null);
        $deadlines = [];
        foreach ((array) ($data['deadlines'] ?? []) as $deadline) {
            if (is_array($deadline) && isset($deadline['id'])) {
                $deadlines[(string) $deadline['id']] = $deadline;
            }
        }

        $items = [];
        foreach ((array) ($data['checklist'] ?? []) as $item) {
            $prepared = $this->prepareItem($item, $facts, $deadlines, $incorporated);
            if ($prepared !== null) {
                $items[] = $prepared;
            }
        }

        // The ambient heuristic (privacy, invoices, employment, contractors)
        // rides alongside the country pack: same shape, `source: radar`, and
        // deduped against a country item that already covers the same ground.
        $seen = array_column($items, 'id');
        foreach ($this->radar->obligationsForProfile($profile) as $obligation) {
            $id = 'radar_'.(string) ($obligation['id'] ?? 'obligation');
            if (($obligation['id'] ?? '') === 'discovery_start' || in_array($id, $seen, true)) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'title' => (string) ($obligation['title'] ?? ''),
                'detail' => (string) ($obligation['summary'] ?? ''),
                'owner' => 'founder',
                'category' => 'operations',
                'severity' => (string) ($obligation['severity'] ?? 'awareness'),
                'conditional' => false,
                'trigger' => null,
                'applies_to' => ['all'],
                'confidence' => 'medium',
                'links' => [],
                'deadline_id' => null,
                'due_on' => null,
                'rule' => null,
                'recurring' => null,
                'source' => 'radar',
                'action' => $obligation['action'] ?? null,
                'action_path' => $obligation['action_path'] ?? null,
            ];
        }

        $validAsOf = $this->date($data['valid_as_of'] ?? null);
        $reviewAfter = (int) ($data['review_after_days'] ?? self::DEFAULT_REVIEW_AFTER_DAYS);
        $daysSince = $validAsOf ? (int) round($validAsOf->diffInDays($today, false)) : null;
        $stale = $daysSince === null || $daysSince > $reviewAfter;

        $awareness = [];
        if ($stale) {
            $awareness[] = [
                'id' => $code.'_rules_may_have_changed',
                'title' => sprintf(
                    'These %s rules were last checked on %s — they may have changed',
                    (string) ($data['name'] ?? $code),
                    $validAsOf?->toDateString() ?? 'an unknown date',
                ),
                'severity' => 'awareness',
                'detail' => 'Confirm registrations, rates and deadlines with the official source or a qualified adviser before you act.',
            ];
        }

        return [
            'jurisdiction' => [
                'code' => (string) ($data['code'] ?? $code),
                'name' => (string) ($data['name'] ?? $code),
                'currency' => (string) ($data['currency'] ?? ''),
                'valid_as_of' => $validAsOf?->toDateString(),
                'review_after_days' => $reviewAfter,
                'days_since_review' => $daysSince,
                'stale' => $stale,
                'structures' => array_values(array_filter((array) ($data['structures'] ?? []), 'is_array')),
                'registration_body' => (string) data_get($data, 'registration.body', ''),
                'tax_authority' => (string) data_get($data, 'tax.authority', ''),
            ],
            'items' => $items,
            'awareness' => $awareness,
            'deadlines' => array_values($deadlines),
            'disclaimer' => (string) ($data['disclaimer'] ?? self::DISCLAIMER),
        ];
    }

    /** Base currency for the finance model, e.g. uk → GBP. */
    public function currency(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        $currency = (string) ($this->load($code)['currency'] ?? '');

        return $currency !== '' ? strtoupper($currency) : null;
    }

    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $facts
     * @param  array<string, array<string, mixed>>  $deadlines
     * @return array<string, mixed>|null
     */
    private function prepareItem(mixed $item, array $facts, array $deadlines, ?Carbon $incorporated): ?array
    {
        if (! is_array($item) || ! isset($item['id'], $item['title'])) {
            return null;
        }

        $applies = array_map('strval', (array) ($item['applies_to'] ?? ['all']));
        $conditional = false;

        if (! in_array('all', $applies, true)) {
            if ($facts['structure'] === null) {
                $conditional = true;       // we cannot tell yet — keep it, flagged
            } elseif (! in_array($facts['structure'], $applies, true)) {
                return null;
            }
        }

        $trigger = $item['trigger'] ?? null;
        if ($trigger !== null) {
            $state = $facts[(string) $trigger] ?? null;
            if ($state === false) {
                return null;
            }
            if ($state === null) {
                $conditional = true;
            }
        }

        $deadline = isset($item['deadline']) ? ($deadlines[(string) $item['deadline']] ?? null) : null;
        $due = $deadline !== null ? $this->dueDate($deadline, $incorporated) : null;

        return [
            'id' => (string) $item['id'],
            'title' => (string) $item['title'],
            'detail' => (string) ($item['detail'] ?? ''),
            'owner' => (string) ($item['owner'] ?? 'founder'),
            'category' => (string) ($item['category'] ?? 'registration'),
            'severity' => (string) ($item['severity'] ?? ($conditional ? 'awareness' : 'action_needed')),
            'conditional' => $conditional,
            'trigger' => $trigger !== null ? (string) $trigger : null,
            'applies_to' => $applies,
            'confidence' => (string) ($item['confidence'] ?? 'medium'),
            'links' => array_values(array_map('strval', (array) ($item['links'] ?? []))),
            'deadline_id' => isset($item['deadline']) ? (string) $item['deadline'] : null,
            'due_on' => $due?->toDateString(),
            'rule' => $deadline['rule'] ?? null,
            'recurring' => $deadline['recurring'] ?? null,
            'source' => 'pack',
        ];
    }

    /**
     * Only `relative_to: incorporation` offsets become real dates — Atlas
     * never guesses a date it cannot know (the rule text explains the rest).
     *
     * @param  array<string, mixed>  $deadline
     */
    private function dueDate(array $deadline, ?Carbon $incorporated): ?Carbon
    {
        if ($incorporated === null || ($deadline['relative_to'] ?? null) !== 'incorporation') {
            return null;
        }

        $base = $incorporated->copy();
        if (isset($deadline['offset_months'])) {
            return $base->addMonths((int) $deadline['offset_months']);
        }
        if (isset($deadline['offset_days'])) {
            return $base->addDays((int) $deadline['offset_days']);
        }

        return null;
    }

    /**
     * Founder answers → the facts the yaml filters on. Free text is read
     * generously ("not sure" stays unknown, i.e. the item stays conditional).
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function facts(array $profile): array
    {
        // Trigger names match the yaml (and the Python service's TRIGGERS):
        // hires, handles_personal_data, regulated_activity, vat_threshold.
        return [
            'structure' => $this->structure($profile['legal_structure'] ?? ($profile['structure'] ?? null)),
            'hires' => $this->tribool($profile['hires'] ?? ($profile['employees_first_year'] ?? ($profile['hires_contractors'] ?? null))),
            'handles_personal_data' => $this->tribool($profile['handles_personal_data'] ?? ($profile['data_handles_pii'] ?? null)),
            'regulated_activity' => $this->tribool($profile['regulated_activity'] ?? null),
            'vat_threshold' => $this->tribool($profile['vat_threshold'] ?? null),
            'already_registered' => $this->tribool($profile['already_registered'] ?? null),
        ];
    }

    private function structure(mixed $value): ?string
    {
        $text = strtolower(trim((string) $value));
        if ($text === '') {
            return null;
        }
        if (str_contains($text, 'not sure') || str_contains($text, 'unsure') || str_contains($text, "don't know")) {
            return null;
        }
        if (str_contains($text, 'sole') || str_contains($text, 'self-employed')) {
            return 'sole_trader';
        }
        if (str_contains($text, 'partner')) {
            return 'partnership';
        }
        if (str_contains($text, 'compan') || str_contains($text, 'ltd') || str_contains($text, 'limited')
            || str_contains($text, 'pty') || str_contains($text, 'pvt') || str_contains($text, '(pvt)')) {
            return 'company';
        }

        return null;
    }

    /** true / false / null — null means "we have not asked, or cannot tell". */
    private function tribool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $text = strtolower(trim((string) $value));
        if ($text === '') {
            return null;
        }
        foreach (['no', 'none', 'not yet', 'never', 'nope', 'nothing'] as $negative) {
            if ($text === $negative || str_starts_with($text, $negative.' ') || str_starts_with($text, $negative.',')) {
                return false;
            }
        }
        if (str_contains($text, 'not sure') || str_contains($text, 'unsure') || str_contains($text, 'maybe')) {
            return null;
        }
        foreach (['yes', 'yep', 'we do', 'i do', 'we will', 'i will', 'already'] as $positive) {
            if (str_contains($text, $positive)) {
                return true;
            }
        }

        // Anything substantive that is not a refusal reads as "yes, this applies".
        return strlen($text) > 3 ? true : null;
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        // Symfony's YAML parser turns a bare `2026-09-16` into a Unix
        // timestamp unless PARSE_DATETIME is set, so `valid_as_of` arrives
        // here as an int.
        if (is_int($value) || is_float($value) || (is_string($value) && ctype_digit(trim($value)) && strlen(trim($value)) > 4)) {
            return Carbon::createFromTimestampUTC((int) $value)->startOfDay();
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        try {
            return Carbon::parse($text)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function reader(): InterviewRunner
    {
        return $this->runner ??= new InterviewRunner(
            (string) config('launch.pack_id', 'business-launch'),
            new ArrayAnswerStore,
        );
    }
}
