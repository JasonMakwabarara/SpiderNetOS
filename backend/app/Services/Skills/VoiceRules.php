<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\Services\Brain\BrainMarkdown;

/**
 * The tenant's own writing prohibitions, parsed out of the brain.
 *
 * Two sections carry them, and the manifest treats both as first-class keys:
 *
 *   brand/voice.md  "## Do and don't"        -> voice.do_dont
 *   people/user.md  "## Never say or offer"  -> people.user.never_say
 *
 * Why this class exists: `SkillOutputValidator::bannedPhrases()` has always
 * read `$facts['banned_phrases']` and `$facts['never_say']`, and
 * `SkillPromptBuilder::facts()` has never set either — so on every real run
 * the validator could only ever see the 24 hardcoded
 * `SkillCard::DEFAULT_BANNED_PHRASES`. A tenant could write "never offer a
 * discount" in their own brain, watch it appear in the prompt, and still have
 * a draft offering a discount pass validation. The rules were enforced in the
 * eval fixtures and nowhere else.
 *
 * The parsers live here, once, so the eval harness and the live run agree
 * about what a rule is. A rule that means one thing in a test and another in
 * production is worse than no rule.
 */
class VoiceRules
{
    public const VOICE_PATH = 'brand/voice.md';

    public const VOICE_SECTION = "Do and don't";

    public const USER_PATH = 'people/user.md';

    public const USER_SECTION = 'Never say or offer';

    /** A rule shorter than this is not a phrase, it is a typo. */
    public const MIN_TERM_LENGTH = 3;

    /**
     * Quoted terms from a "Don't say …" line.
     *
     * Only quoted terms, deliberately. "Don't say leverage, solutions or
     * seamless" without quotes is prose about tone; the quotes are how the
     * owner marks a literal string they never want to appear. Guessing at
     * unquoted words would ban "solutions" from a company that sells them.
     *
     * @return list<string>
     */
    public static function bannedPhrasesFrom(?string $voiceMarkdown): array
    {
        if ($voiceMarkdown === null || trim($voiceMarkdown) === '') {
            return [];
        }

        $section = BrainMarkdown::section($voiceMarkdown, self::VOICE_SECTION) ?? $voiceMarkdown;

        $phrases = [];
        if (preg_match_all('/don\'?t\s+(?:say|use|write)\s*(.+?)(?:\.\s|\.$|\n|$)/iu', $section, $lines)) {
            foreach ($lines[1] as $line) {
                preg_match_all('/"([^"]+)"|“([^”]+)”|\'([^\']+)\'|`([^`]+)`/u', $line, $quoted);
                foreach (array_merge($quoted[1], $quoted[2], $quoted[3], $quoted[4]) as $term) {
                    $term = trim($term);
                    if (mb_strlen($term) >= self::MIN_TERM_LENGTH) {
                        $phrases[] = $term;
                    }
                }
            }
        }

        return array_values(array_unique($phrases));
    }

    /**
     * The object of each "Never …" sentence.
     *
     *   "Never offer a discount."                  -> "discount"
     *   "Never compare us to a named competitor."  -> "compare us to a named competitor"
     *
     * These are matched as substrings by the validator, so the object is what
     * matters, not the instruction around it.
     *
     * @return list<string>
     */
    public static function neverSayFrom(?string $userMarkdown): array
    {
        if ($userMarkdown === null || trim($userMarkdown) === '') {
            return [];
        }

        $section = BrainMarkdown::section($userMarkdown, self::USER_SECTION);
        if ($section === null || trim($section) === '') {
            return [];
        }

        $terms = [];
        foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $section) ?: [] as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            if (preg_match('/^\W*never\s+(?:say|offer|mention|promise|use|quote|give|claim)\s+(?:a\s+|an\s+|the\s+|any\s+)?(.+?)[.!?]?\s*$/iu', $sentence, $m)) {
                $terms[] = trim($m[1]);
            } elseif (preg_match('/^\W*never\s+(.+?)[.!?]?\s*$/iu', $sentence, $m)) {
                $terms[] = trim($m[1]);
            }
        }

        return array_values(array_unique(array_filter(
            $terms,
            fn (string $term): bool => mb_strlen($term) >= self::MIN_TERM_LENGTH,
        )));
    }
}
