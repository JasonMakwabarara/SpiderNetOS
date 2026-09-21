<?php

declare(strict_types=1);

namespace App\Services\Skills\Eval;

/**
 * One authority for what an eval property type is.
 *
 * This replaces `PropertyChecker::ARRAY_TYPES` and `STRING_TYPES`, which
 * declared exactly this vocabulary, sat directly above the switch they
 * described, and were read by nothing in the repository. They were correct, and
 * nothing would have noticed if they were not — which is the whole argument for
 * the registry being the dispatch table rather than a second list beside one.
 *
 * A type with no entry here has no handler and cannot execute. That removes one
 * failure mode: a second dispatch table disagreeing with the declaration. It
 * removes none of these, and each has its own check in PropertyRegistryTest — a
 * handler method that does not exist, a wrong argument shape, two names sharing
 * an unsuitable handler, and a handler that exists and always passes.
 *
 * `status` is what lets this file be complete and honest before the handlers
 * exist, the same device as `.github/deferred.yaml`: registered debt with an
 * owner and a review date rather than a comment. A `planned` type can never
 * pass an eval, and from the end of Stage 1 naming one in a case is a
 * `skills:validate` error.
 *
 * The shape follows `ConnectorRegistry` (a private const table plus
 * all/has/get) rather than `ToolCatalogue`'s `register()`, because property
 * types are a fixed vocabulary known at authoring time, not an open set
 * arriving at runtime.
 */
final class PropertyRegistry
{
    public const IMPLEMENTED = 'implemented';

    public const PLANNED = 'planned';

    /** Owner and review date carried by every `planned` entry. */
    private const DEBT_OWNER = 'jason';

    private const DEBT_REVIEW = '2026-12-19';

    /**
     * name => {arg, status, handler, summary, collapses_into?}
     *
     * `collapses_into` records the migration target for a planned type: the
     * primitive that will absorb it, or `domain` when the assertion encodes one
     * skill's semantics and a generic name would hide what it checks.
     *
     * @var array<string, array<string, mixed>>
     */
    private const CATALOGUE = [
        // ------------------------------------------------------------------ //
        //  Implemented — 21 handlers. `no_banned_phrase` (singular) is gone:
        //  nothing used it, nothing collapses onto it, and the plural already
        //  reads $p['phrases'] as well as the house rules — so the two names
        //  differed by one character and the shorter one silently dropped the
        //  brand voice and default bans from a safety check.
        //  Nine of them are used by no case in the corpus; they are the
        //  primitives the 51 collapse onto, written and never wired up.
        // ------------------------------------------------------------------ //
        'has_key' => ['arg' => PropertyArg::Structured, 'handler' => 'checkHasKey',
            'summary' => 'The dotted path exists in the output.'],
        'count' => ['arg' => PropertyArg::Structured, 'handler' => 'checkCount',
            'summary' => 'The list at <path> has equals / at least min / at most max elements.'],
        'enum' => ['arg' => PropertyArg::Structured, 'handler' => 'checkEnum',
            'summary' => 'The scalar at <path> is one of values[].'],
        'cites_fact' => ['arg' => PropertyArg::Structured, 'handler' => 'checkCitesFact',
            'retained' => 'No case uses it and nothing collapses onto it, but it is a real primitive with no duplicate: "the output quotes a fact from the brain" is the assertion mentions_proof_point makes fuzzily and this one makes exactly.',
            'summary' => 'The output quotes a fact, or one of any_of[], or a fact line from a brain file.'],
        'max_length' => ['arg' => PropertyArg::Structured, 'handler' => 'checkLength',
            'summary' => 'The text at <path> is at most max chars, or words when unit is words.'],
        'min_length' => ['arg' => PropertyArg::Structured, 'handler' => 'checkLength',
            'summary' => 'The text at <path> is at least min chars, or words when unit is words.'],
        'contains' => ['arg' => PropertyArg::Structured, 'handler' => 'checkContains',
            'summary' => 'The text at <path> contains text, case-insensitively.'],
        'matches' => ['arg' => PropertyArg::Structured, 'handler' => 'checkMatches',
            'retained' => 'The escape hatch. Unused deliberately — a case reaching for a raw regex usually wants a named property instead — but removing it would leave no way to assert anything the vocabulary does not cover.',
            'summary' => 'The text at <path> matches a delimited PCRE pattern.'],

        'valid_json' => ['arg' => PropertyArg::None, 'handler' => 'checkValidJson',
            'summary' => 'The output decoded to a JSON object rather than prose.'],
        'schema_valid' => ['arg' => PropertyArg::None, 'handler' => 'checkSchemaValid',
            'summary' => "SkillOutputValidator's verdict. Skips when no validator is available."],
        'steps_count' => ['arg' => PropertyArg::Int, 'cardinality' => Cardinality::Root, 'counts' => 'steps', 'bound' => 'equals', 'handler' => 'checkStepsCount',
            'summary' => 'steps[] has exactly N elements.'],
        'beats_in_order' => ['arg' => PropertyArg::Csv, 'handler' => 'checkBeatsInOrder',
            'summary' => 'steps[].beat is exactly this sequence — order and length both.'],
        'subjects_per_step' => ['arg' => PropertyArg::Int, 'cardinality' => Cardinality::EveryMatchNonEmpty, 'handler' => 'checkSubjectsPerStep',
            'summary' => 'Every step carries exactly N subjects.'],
        'single_cta_per_step' => ['arg' => PropertyArg::None, 'cardinality' => Cardinality::EveryMatchNonEmpty, 'handler' => 'checkSingleCtaPerStep',
            'summary' => 'Every step has a cta, and its body asks at most one question.'],
        'no_unverified_figures' => ['arg' => PropertyArg::None, 'handler' => 'checkNoUnverifiedFigures',
            'summary' => 'Every percentage and currency amount appears in the fixture brain.'],
        'links_allowlisted' => ['arg' => PropertyArg::None, 'handler' => 'checkLinksAllowlisted',
            'summary' => "Every link's host appears in the fixture brain."],
        'no_banned_phrases' => ['arg' => PropertyArg::None, 'handler' => 'checkNoBannedPhrases',
            'summary' => "Property phrases, brand voice.md don't-say terms and the card defaults."],
        'mentions_proof_point' => ['arg' => PropertyArg::None, 'handler' => 'checkMentionsProofPoint',
            'summary' => 'The output overlaps a proof point from offer/offer.md by 60% of its distinctive words.'],
        'respects_never_say' => ['arg' => PropertyArg::None, 'handler' => 'checkRespectsNeverSay',
            'summary' => 'No never-say term from people/user.md appears. Skips when the fixture declares none.'],
        'personalisation_slot_present' => ['arg' => PropertyArg::Int, 'handler' => 'checkPersonalisationSlotPresent',
            'summary' => 'At least N personalisation slots, counting step fields and {{...}} markers.'],
        'blocked_missing_brain' => ['arg' => PropertyArg::BrainRef, 'handler' => 'checkBlockedMissingBrain',
            'summary' => 'The run blocked, naming this path#Section as the missing prerequisite.'],

        // ------------------------------------------------------------------ //
        //  Planned — named by the corpus, implemented by nothing.
        //  43 collapse onto a primitive, 8 stay domain. Verified as a partition:
        //  no name in both groups, none in neither.
        // ------------------------------------------------------------------ //

        // --- select an item by the id the case fed in ---
        'classification' => ['arg' => PropertyArg::Selector, 'cardinality' => Cardinality::ExactlyOne, 'collapses_into' => 'field',
            'summary' => 'items[message_id=<id>].classification equals <value>.'],
        'draft_action' => ['arg' => PropertyArg::Selector, 'cardinality' => Cardinality::ExactlyOne, 'collapses_into' => 'field',
            'summary' => 'items[message_id=<id>].draft_action equals <value>.'],
        'draft_action_in' => ['arg' => PropertyArg::SelectorCsv, 'cardinality' => Cardinality::ExactlyOne, 'collapses_into' => 'enum',
            'summary' => 'items[message_id=<id>].draft_action is one of <a,b>.'],
        'draft_reply_null' => ['arg' => PropertyArg::SelectorOnly, 'cardinality' => Cardinality::ExactlyOne, 'collapses_into' => 'is_null',
            'summary' => 'items[message_id=<id>].draft_reply is null. Absent and null are different states.'],
        'angle' => ['arg' => PropertyArg::Selector, 'cardinality' => Cardinality::ExactlyOne, 'collapses_into' => 'field',
            'summary' => 'items[thread_id=<id>].angle equals <value>.'],
        'angle_in' => ['arg' => PropertyArg::SelectorCsv, 'cardinality' => Cardinality::ExactlyOne, 'collapses_into' => 'enum',
            'summary' => 'items[thread_id=<id>].angle is one of <a,b>.'],
        'is_close_out' => ['arg' => PropertyArg::Selector, 'cardinality' => Cardinality::ExactlyOne, 'collapses_into' => 'field',
            'summary' => 'items[thread_id=<id>].is_close_out equals <bool>.'],

        // --- a scalar at the root ---
        'status' => ['arg' => PropertyArg::Str, 'collapses_into' => 'field',
            'summary' => 'status equals <value>.'],
        'timezone' => ['arg' => PropertyArg::Str, 'collapses_into' => 'field',
            'summary' => 'timezone equals <value>.'],
        'duration_minutes' => ['arg' => PropertyArg::Int, 'collapses_into' => 'field',
            'summary' => 'duration_minutes equals <n>.'],
        'booking_link' => ['arg' => PropertyArg::Url, 'collapses_into' => 'field',
            'summary' => 'booking_link equals <url>.'],
        'path' => ['arg' => PropertyArg::Path, 'collapses_into' => 'field',
            'summary' => 'path equals <brain path>.'],
        'disqualified' => ['arg' => PropertyArg::Bool, 'collapses_into' => 'field',
            'summary' => 'disqualified equals <bool>. The key is optional in the schema, so absent and false differ.'],
        'fit_score_gte' => ['arg' => PropertyArg::Int, 'collapses_into' => 'field',
            'summary' => 'fit_score is at least <n>.'],
        'fit_score_lte' => ['arg' => PropertyArg::Int, 'collapses_into' => 'field',
            'summary' => 'fit_score is at most <n>.'],
        'confidence_lte' => ['arg' => PropertyArg::Float, 'collapses_into' => 'field',
            'summary' => 'confidence is at most <n>. Ambiguous with angles[].confidence — the path disambiguates.'],

        // --- how many ---
        'items_count' => ['arg' => PropertyArg::Int, 'cardinality' => Cardinality::Root, 'counts' => 'items', 'bound' => 'equals', 'collapses_into' => 'count',
            'summary' => 'items[] has exactly <n> elements. Zero is a legitimate answer for a batch skill.'],
        'messages_count' => ['arg' => PropertyArg::Int, 'cardinality' => Cardinality::Root, 'counts' => 'messages', 'bound' => 'equals', 'collapses_into' => 'count',
            'summary' => 'messages[] has exactly <n> elements.'],
        'slots_count' => ['arg' => PropertyArg::IntOrSelector, 'cardinality' => Cardinality::ExactlyOne, 'counts' => 'slots', 'bound' => 'equals', 'collapses_into' => 'count',
            'summary' => 'slots[] has <n> elements — at the root, or inside the item named by a selector.'],
        'angles_min' => ['arg' => PropertyArg::Int, 'cardinality' => Cardinality::Root, 'counts' => 'angles', 'bound' => 'min', 'collapses_into' => 'count',
            'summary' => 'angles[] has at least <n> elements.'],
        'blocks_between' => ['arg' => PropertyArg::Range, 'cardinality' => Cardinality::Root, 'counts' => 'blocks', 'bound' => 'range', 'collapses_into' => 'count',
            'summary' => 'blocks[] has between <low> and <high> elements.'],
        'segments_count_between' => ['arg' => PropertyArg::Range, 'cardinality' => Cardinality::Root, 'counts' => 'segments', 'bound' => 'range', 'collapses_into' => 'count',
            'summary' => 'segments[] has between <low> and <high> elements.'],
        'personalisation_lines_count' => ['arg' => PropertyArg::Int, 'cardinality' => Cardinality::Root, 'counts' => 'personalisation_lines', 'bound' => 'equals', 'collapses_into' => 'count',
            'summary' => 'personalisation_lines[] has exactly <n> elements.'],
        'frontmatter_tone_count_between' => ['arg' => PropertyArg::Range, 'cardinality' => Cardinality::Root, 'counts' => 'frontmatter.tone', 'bound' => 'range', 'collapses_into' => 'count',
            'summary' => 'frontmatter.tone[] has between <low> and <high> entries.'],
        'subject_variants' => ['arg' => PropertyArg::Int, 'cardinality' => Cardinality::Root, 'counts' => 'subjects', 'bound' => 'equals', 'collapses_into' => 'count',
            'summary' => 'Exactly <n> subject variants. The card emits subject_a/subject_b, not a subjects[] array.'],

        // --- how long ---
        'subject_max' => ['arg' => PropertyArg::Int, 'collapses_into' => 'max_length',
            'summary' => 'Every items[].subject is at most <n> characters.'],
        'subject_length_max' => ['arg' => PropertyArg::Int, 'collapses_into' => 'max_length',
            'summary' => 'Every subject variant is at most <n> characters. Same assertion as subject_max, other skill.'],
        'body_max' => ['arg' => PropertyArg::Int, 'collapses_into' => 'max_length',
            'summary' => 'Every items[].body is at most <n> characters.'],
        'connect_note_max' => ['arg' => PropertyArg::Int, 'collapses_into' => 'max_length',
            'summary' => 'connect_note is at most <n> characters.'],
        'section_absent_or_short' => ['arg' => PropertyArg::Str, 'collapses_into' => 'max_length',
            'summary' => 'The named section is absent, or short. Needs max_length to declare when_missing: pass.'],
        'section_min_chars' => ['arg' => PropertyArg::SectionInt, 'collapses_into' => 'min_length',
            'summary' => 'The named section is at least <n> characters.'],

        // --- what the text says ---
        'section_present' => ['arg' => PropertyArg::Str, 'collapses_into' => 'has_key',
            'summary' => 'sections carries the named key. sections is a JSON object, so this needs no markdown parsing.'],
        'section_contains' => ['arg' => PropertyArg::SectionValue, 'collapses_into' => 'contains',
            'summary' => 'The named section contains the given text.'],
        'do_dont_contains' => ['arg' => PropertyArg::Phrase, 'collapses_into' => 'contains',
            'summary' => 'The "Do and don\'t" section contains the phrase.'],
        'read_back_contains' => ['arg' => PropertyArg::Phrase, 'collapses_into' => 'contains',
            'summary' => 'read_back contains the phrase.'],
        'body_not_contains' => ['arg' => PropertyArg::Phrase, 'collapses_into' => 'not_contains',
            'summary' => 'No items[].body contains the phrase.'],
        'report_not_contains' => ['arg' => PropertyArg::Phrase, 'collapses_into' => 'not_contains',
            'summary' => 'The whole output contains the phrase nowhere.'],
        'frontmatter_tone_not_contains' => ['arg' => PropertyArg::Phrase, 'collapses_into' => 'not_contains',
            'summary' => 'No frontmatter.tone entry contains the phrase.'],
        'no_links_in_connect_note' => ['arg' => PropertyArg::None, 'collapses_into' => 'not_matches',
            'summary' => 'connect_note carries no URL.'],

        // --- sets and presence ---
        'items_thread_ids' => ['arg' => PropertyArg::Csv, 'collapses_into' => 'set_equals',
            'summary' => 'The set of items[].thread_id is exactly this set.'],
        'block_roles_include' => ['arg' => PropertyArg::Csv, 'collapses_into' => 'set_includes',
            'summary' => 'The set of blocks[].role includes all of these.'],
        'no_cta_block' => ['arg' => PropertyArg::None, 'cardinality' => Cardinality::EveryMatchMayBeEmpty, 'pairs_with' => 'blocks', 'collapses_into' => 'set_excludes',
            'summary' => 'No block has role cta.'],
        'proof_has_source' => ['arg' => PropertyArg::None, 'cardinality' => Cardinality::EveryMatchMayBeEmpty, 'pairs_with' => 'blocks', 'collapses_into' => 'not_empty',
            'summary' => 'Every blocks[role=proof] has a non-empty source. The schema leaves source optional, so presence is the whole claim.'],

        // ------------------------------------------------------------------ //
        //  The eight new primitives. Six more of the fourteen collapse targets
        //  already existed and had never been used; these are the ones that
        //  genuinely did not exist. Each addresses the output through
        //  PropertyPath and is subject to its declared cardinality — no handler
        //  interprets an array, a wildcard or a missing path on its own.
        // ------------------------------------------------------------------ //
        'field' => ['arg' => PropertyArg::Structured, 'handler' => 'checkField', 'cardinality' => Cardinality::ExactlyOne,
            'summary' => 'The value at <path>[.<field>] equals / is at least / is at most the given value. One subject: a selector that matches nothing or twice is an output failure.'],
        'is_null' => ['arg' => PropertyArg::Structured, 'handler' => 'checkIsNull', 'cardinality' => Cardinality::ExactlyOne,
            'summary' => 'The value at <path>[.<field>] is present and null. Absent is a different state and fails, because the schemas that use this make the key optional AND nullable.'],
        'not_contains' => ['arg' => PropertyArg::Structured, 'handler' => 'checkNotContains',
            'summary' => 'The text at <path> does not contain <text>, case-insensitively.'],
        'not_matches' => ['arg' => PropertyArg::Structured, 'handler' => 'checkNotMatches',
            'summary' => 'The text at <path> does not match <pattern>.'],
        'not_empty' => ['arg' => PropertyArg::Structured, 'handler' => 'checkNotEmpty',
            'summary' => 'The value at <path>[.<field>] is present and not empty. Null, "", [] and whitespace are all empty; false and 0 are not.'],
        'set_equals' => ['arg' => PropertyArg::Structured, 'handler' => 'checkSetEquals', 'cardinality' => Cardinality::EveryMatchNonEmpty,
            'summary' => 'The set of values at <path> is exactly values[]. Order and duplicates are ignored.'],
        'set_includes' => ['arg' => PropertyArg::Structured, 'handler' => 'checkSetIncludes', 'cardinality' => Cardinality::EveryMatchNonEmpty,
            'summary' => 'The set of values at <path> includes every one of values[].'],
        'set_excludes' => ['arg' => PropertyArg::Structured, 'handler' => 'checkSetExcludes', 'cardinality' => Cardinality::EveryMatchNonEmpty,
            'summary' => 'The set of values at <path> contains none of values[]. Non-empty on purpose: "no block has role cta" over zero blocks establishes nothing.'],

        // ------------------------------------------------------------------ //
        //  Stay domain — the assertion encodes one skill's semantics, and a
        //  generic name would hide what is actually being checked.
        // ------------------------------------------------------------------ //
        'single_cta' => ['arg' => PropertyArg::None, 'collapses_into' => 'domain',
            'summary' => 'One call to action. Counting links or question marks is not the same assertion.'],
        'single_ask_per_message' => ['arg' => PropertyArg::None, 'cardinality' => Cardinality::EveryMatchNonEmpty, 'collapses_into' => 'domain',
            'summary' => 'Each message makes one ask — natural-language question counting, not a regex.'],
        'slots_within_hours' => ['arg' => PropertyArg::TimeRange, 'cardinality' => Cardinality::EveryMatchNonEmpty, 'collapses_into' => 'domain',
            'summary' => 'Every slot starts inside the window, read from the ISO-8601 offsets in slots[] as written. Returns error rather than guessing if a case supplies a timezone configuration it cannot honour.'],
        'every_angle_has_source' => ['arg' => PropertyArg::None, 'cardinality' => Cardinality::EveryMatchMayBeEmpty, 'pairs_with' => 'angles', 'collapses_into' => 'domain',
            'summary' => "Every angles[].source appears in the output's own sources[]. Cross-field, and not what not_empty would check — the schema already requires the field to exist."],
        'sources_subset_of_fixture' => ['arg' => PropertyArg::None, 'cardinality' => Cardinality::EveryMatchMayBeEmpty, 'pairs_with' => 'sources', 'collapses_into' => 'domain',
            'summary' => 'Every sources[] entry appears in the fixture the case supplied.'],
        'voice_section_verbatim' => ['arg' => PropertyArg::AnswerId, 'collapses_into' => 'domain',
            'summary' => 'sections.Voice carries inputs.answers.<id> verbatim, whitespace normalised.'],
        'read_back_quotes_owner' => ['arg' => PropertyArg::None, 'collapses_into' => 'domain',
            'summary' => "read_back quotes the owner's own words rather than paraphrasing them."],
        'read_back_names_question' => ['arg' => PropertyArg::AnswerId, 'collapses_into' => 'domain',
            'summary' => 'read_back names the question this answer came from.'],
    ];

    /** @return array<string, PropertyType> */
    public static function all(): array
    {
        static $hydrated = null;

        if ($hydrated === null) {
            $hydrated = [];
            foreach (self::CATALOGUE as $name => $row) {
                $hydrated[$name] = self::hydrate($name, $row);
            }
        }

        return $hydrated;
    }

    public static function has(string $name): bool
    {
        return isset(self::CATALOGUE[$name]);
    }

    public static function get(string $name): ?PropertyType
    {
        return self::all()[$name] ?? null;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /** @return list<string> types with a handler that runs today */
    public static function implemented(): array
    {
        return array_keys(array_filter(self::all(), fn (PropertyType $t): bool => $t->isImplemented()));
    }

    /** @return list<string> declared, owned, and not yet written */
    public static function planned(): array
    {
        return array_keys(array_filter(self::all(), fn (PropertyType $t): bool => ! $t->isImplemented()));
    }

    /**
     * Why a property cannot be evaluated, or null when it can.
     *
     * Two distinct reasons, and the caller needs to tell them apart: a name
     * nothing declares is a different problem from a name declared and awaiting
     * a handler, and only the first means somebody typed something wrong.
     */
    public static function reject(string $name, ?string $arg): ?string
    {
        $type = self::get($name);

        if ($type === null) {
            return "unknown property type \"{$name}\"";
        }

        if (! $type->isImplemented()) {
            return "property type \"{$name}\" is declared but has no handler yet"
                .($type->collapsesInto !== null ? " (collapses into {$type->collapsesInto})" : '');
        }

        return $type->arg->reject($arg, $name);
    }

    /** @param  array<string, mixed>  $row */
    private static function hydrate(string $name, array $row): PropertyType
    {
        $handler = $row['handler'] ?? null;

        return new PropertyType(
            name: $name,
            arg: $row['arg'],
            status: $handler !== null ? self::IMPLEMENTED : self::PLANNED,
            handler: is_string($handler) ? $handler : null,
            summary: (string) ($row['summary'] ?? ''),
            cardinality: $row['cardinality'] ?? Cardinality::Root,
            pairsWith: isset($row['pairs_with']) ? (string) $row['pairs_with'] : null,
            counts: isset($row['counts']) ? (string) $row['counts'] : null,
            bound: isset($row['bound']) ? (string) $row['bound'] : null,
            retained: isset($row['retained']) ? (string) $row['retained'] : null,
            collapsesInto: isset($row['collapses_into']) ? (string) $row['collapses_into'] : null,
            owner: $handler !== null ? null : self::DEBT_OWNER,
            reviewDate: $handler !== null ? null : self::DEBT_REVIEW,
        );
    }
}
