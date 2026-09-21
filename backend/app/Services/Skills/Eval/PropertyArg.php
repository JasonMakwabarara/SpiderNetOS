<?php

declare(strict_types=1);

namespace App\Services\Skills\Eval;

/**
 * The argument shapes a property type can take, and the one place that knows
 * how to tell a well-formed argument from a malformed one.
 *
 * This exists because `unknown property type` was not the only way a case could
 * be wrong. `slots_count:two` names a type that exists and an argument that
 * cannot work, and before the registry the only way to find out was to run the
 * eval and read a confusing failure. Validation now happens in skills:validate,
 * before a model is called.
 */
enum PropertyArg: string
{
    /** No argument: `single_cta`, `valid_json`. */
    case None = 'none';

    /** A whole number: `subject_max:80`. */
    case Int = 'int';

    /** A decimal: `confidence_lte:0.4`. */
    case Float = 'float';

    /** `true` or `false`: `disqualified:false`. */
    case Bool = 'bool';

    /** Any non-empty token: `status:proposed`, `timezone:Europe/London`. */
    case Str = 'string';

    /** A comma-separated list: `block_roles_include:story,tip`. */
    case Csv = 'csv';

    /** Two whole numbers, low first: `blocks_between:3,5`. */
    case Range = 'range';

    /** A quoted phrase: `body_not_contains:"circling back"`. */
    case Phrase = 'phrase';

    /**
     * An item in a list output, addressed by its id: `classification:m1=hot`.
     * The id is the message_id / thread_id the case fed in. This is the
     * convention 51 unimplemented types were each about to reinvent.
     */
    case Selector = 'selector';

    /** A selector whose value is a list: `draft_action_in:m3=none,reply`. */
    case SelectorCsv = 'selector_csv';

    /**
     * An item id on its own, with no value: `draft_reply_null:m4`.
     *
     * A real spelling in four cases, and Selector rejected all four — the
     * assertion is about the item, not about a value it should equal.
     */
    case SelectorOnly = 'selector_only';

    /**
     * A reference into the fixture brain, optionally with a section:
     * `blocked_missing_brain:customers/objections.md#Objections and answers`.
     * Section names carry spaces, which is why this is not Path.
     */
    case BrainRef = 'brain_ref';

    /**
     * The id of an answer the case fed in: `voice_section_verbatim:sample_sentences`
     * resolves against `inputs.answers.<id>`. Validating it here is what stops
     * `voice_section_verbatim:not_an_answer` ever reaching a model.
     */
    case AnswerId = 'answer_id';

    /** A section and a value: `section_contains:"Who we sell to"="1-3 sites"`. */
    case SectionValue = 'section_value';

    /** A section and a number: `section_min_chars:"Who we sell to"=100`. */
    case SectionInt = 'section_int';

    /** A window of local time: `slots_within_hours:08:00-12:00`. */
    case TimeRange = 'time_range';

    /** An absolute URL: `booking_link:https://cal.example/x`. */
    case Url = 'url';

    /** A brain path: `path:brand/voice.md`. */
    case Path = 'path';

    /** An integer, or a selector carrying one: `slots_count:2`, `slots_count:m1=2`. */
    case IntOrSelector = 'int_or_selector';

    /**
     * Properties written in the array form the checker also accepts
     * ({type: count, path: items, equals: 3}). Their arguments are keys on the
     * property itself, not a suffix, so there is nothing to parse here.
     */
    case Structured = 'structured';

    /**
     * @return string|null an error describing why $arg does not fit, or null when it does
     */
    public function reject(?string $arg, string $type): ?string
    {
        $given = $arg === null ? '' : trim($arg);

        if ($this === self::Structured) {
            return null;
        }

        if ($this === self::None) {
            return $given === '' ? null : "{$type} takes no argument, got \"{$given}\"";
        }

        if ($given === '') {
            return "{$type} needs an argument ({$this->value})";
        }

        return match ($this) {
            self::Int => preg_match('/^-?\d+$/', $given) === 1
                ? null : "{$type}: expected a whole number, got \"{$given}\"",
            self::Float => is_numeric($given)
                ? null : "{$type}: expected a number, got \"{$given}\"",
            self::Bool => in_array(mb_strtolower($given), ['true', 'false'], true)
                ? null : "{$type}: expected true or false, got \"{$given}\"",
            self::Range => preg_match('/^\s*-?\d+\s*,\s*-?\d+\s*$/', $given) === 1
                ? null : "{$type}: expected two whole numbers \"low,high\", got \"{$given}\"",
            // A one-element list is legitimate (`block_roles_include:story`), so
            // the claim is not "contains a comma" — it is "no empty entries".
            // The previous spelling, `str_contains(',') || $given !== ''`, was
            // true for every non-empty string and could not reject anything.
            self::Csv => self::listIsWellFormed($given)
                ? null : "{$type}: expected a comma-separated list with no empty entries, got \"{$given}\"",
            // The docblock always said "a quoted phrase" and all twelve corpus
            // usages are quoted, but the check was `$given !== ''` — and the
            // empty case returns earlier, so it was true for everything that
            // reached it. Third condition in this enum that could not fail.
            self::Phrase => preg_match('/^(["\']).+\1$/s', $given) === 1
                ? null : "{$type}: expected a quoted phrase like \"circling back\", got \"{$given}\"",
            self::Selector => self::splitsOnEquals($given)
                ? null : "{$type}: expected \"item_id=value\", got \"{$given}\"",
            self::SelectorCsv => self::splitsOnEquals($given) && self::listIsWellFormed(mb_substr($given, (int) mb_strpos($given, '=') + 1))
                ? null : "{$type}: expected \"item_id=a,b\", got \"{$given}\"",
            self::SelectorOnly => ! str_contains($given, '=')
                ? null : "{$type}: expected a bare item id, got \"{$given}\"",
            self::BrainRef => preg_match('~^[\w./-]+\.\w+(?:#[^#]+)?$~', $given) === 1
                ? null : "{$type}: expected \"path/file.md\" or \"path/file.md#Section\", got \"{$given}\"",
            self::AnswerId => preg_match('/^[a-z][a-z0-9_]*$/i', $given) === 1
                ? null : "{$type}: expected an answer id, got \"{$given}\"",
            self::SectionValue => self::splitsOnEquals($given)
                ? null : "{$type}: expected \"Section\"=\"value\", got \"{$given}\"",
            self::SectionInt => self::sectionInt($given)
                ? null : "{$type}: expected \"Section\"=<number>, got \"{$given}\"",
            self::TimeRange => preg_match('/^\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}$/', $given) === 1
                ? null : "{$type}: expected \"HH:MM-HH:MM\", got \"{$given}\"",
            self::Url => preg_match('~^https?://\S+$~i', $given) === 1
                ? null : "{$type}: expected an absolute URL, got \"{$given}\"",
            self::Path => preg_match('~^[\w./-]+\.\w+$~', $given) === 1
                ? null : "{$type}: expected a brain path, got \"{$given}\"",
            self::IntOrSelector => preg_match('/^-?\d+$/', $given) === 1 || self::splitsOnEquals($given)
                ? null : "{$type}: expected a number or \"item_id=<number>\", got \"{$given}\"",
            self::Str => null,
        };
    }

    /** A comma-separated list with at least one entry and no empty ones. */
    private static function listIsWellFormed(string $given): bool
    {
        // `explode` never returns an empty array, so a `$parts !== []` clause
        // would assert nothing. The empty-entry test is the whole check, and
        // the empty string itself is rejected before reaching here.
        $parts = array_map('trim', explode(',', $given));

        return ! in_array('', $parts, true);
    }

    /** `a=b` with something either side, tolerating quotes around either. */
    private static function splitsOnEquals(string $given): bool
    {
        $at = mb_strpos($given, '=');
        if ($at === false || $at === 0) {
            return false;
        }

        return trim(mb_substr($given, $at + 1)) !== '';
    }

    private static function sectionInt(string $given): bool
    {
        $at = mb_strrpos($given, '=');
        if ($at === false || $at === 0) {
            return false;
        }

        return preg_match('/^-?\d+$/', trim(mb_substr($given, $at + 1))) === 1;
    }
}
