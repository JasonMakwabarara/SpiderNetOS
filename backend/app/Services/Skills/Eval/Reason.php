<?php

declare(strict_types=1);

namespace App\Services\Skills\Eval;

/**
 * Why a property check reached the result it did.
 *
 * `no_banned_phrase` is the reason this exists. Before it was deleted, the test
 * asserting `failed` meant "the known check found prohibited content". After
 * deletion the same assertion still passed, and meant "an unknown property could
 * not execute". Same colour, different meaning — and a suite reading only the
 * colour accepts both while one of them has stopped testing content safety.
 *
 * So the status is not the contract. A negative test pins the reason, which
 * distinguishes MaxLengthExceeded from UnknownProperty, PathMissing and
 * InvalidArgument — four ways to be red that mean four different things.
 *
 * Detail strings stay human and stay editable. Freezing them byte-for-byte was
 * the right instrument for moving the switch into the registry, because a pure
 * transposition should change nothing; it is the wrong instrument for a
 * long-lived contract, where it would make rewording a breaking change.
 *
 * The five-outcome accounting (plan §1.0b) is derived from these in a later
 * commit: Unknown* and Invalid* become `error`, the *Unavailable cases become
 * `unavailable`, and the rest are `pass`/`fail`. That mapping is why the
 * distinction is recorded here even where today's status does not use it.
 */
enum Reason: string
{
    // ---------------------------------------------------------------- //
    //  Satisfied
    // ---------------------------------------------------------------- //
    case Satisfied = 'SATISFIED';

    // ---------------------------------------------------------------- //
    //  Dispatch — the assertion never ran, so it is not evidence either way
    // ---------------------------------------------------------------- //
    case UnknownProperty = 'UNKNOWN_PROPERTY';
    case PropertyNotImplemented = 'PROPERTY_NOT_IMPLEMENTED';
    case InvalidArgument = 'INVALID_ARGUMENT';

    // ---------------------------------------------------------------- //
    //  Structure — the output could not be addressed as the case expects
    // ---------------------------------------------------------------- //
    case PathMissing = 'PATH_MISSING';
    case TypeMismatch = 'TYPE_MISMATCH';
    case NotJsonObject = 'NOT_JSON_OBJECT';
    case StepsMissing = 'STEPS_MISSING';

    // ---------------------------------------------------------------- //
    //  A dependency the check needs was not supplied. Distinct from a
    //  violation: nothing was tested, so nothing was proved.
    // ---------------------------------------------------------------- //
    case ValidatorUnavailable = 'VALIDATOR_UNAVAILABLE';
    case NoFactsAvailable = 'NO_FACTS_AVAILABLE';
    case NoProofPointsAvailable = 'NO_PROOF_POINTS_AVAILABLE';
    case NoNeverSayRulesAvailable = 'NO_NEVER_SAY_RULES_AVAILABLE';

    // ---------------------------------------------------------------- //
    //  Violations — the check ran and the output failed it
    // ---------------------------------------------------------------- //
    case CountMismatch = 'COUNT_MISMATCH';
    case EnumValueNotAllowed = 'ENUM_VALUE_NOT_ALLOWED';
    case MaxLengthExceeded = 'MAX_LENGTH_EXCEEDED';
    case MinLengthNotMet = 'MIN_LENGTH_NOT_MET';
    case TextNotFound = 'TEXT_NOT_FOUND';
    case PatternNotMatched = 'PATTERN_NOT_MATCHED';
    case FactNotCited = 'FACT_NOT_CITED';
    case SchemaInvalid = 'SCHEMA_INVALID';
    case StepCountMismatch = 'STEP_COUNT_MISMATCH';
    case BeatOrderMismatch = 'BEAT_ORDER_MISMATCH';
    case SubjectCountMismatch = 'SUBJECT_COUNT_MISMATCH';
    case CtaMissing = 'CTA_MISSING';
    case MultipleQuestionsInBody = 'MULTIPLE_QUESTIONS_IN_BODY';
    case FigureNotVerified = 'FIGURE_NOT_VERIFIED';
    case LinkNotAllowlisted = 'LINK_NOT_ALLOWLISTED';
    case BannedPhrasePresent = 'BANNED_PHRASE_PRESENT';
    case ProofPointMissing = 'PROOF_POINT_MISSING';
    case NeverSayViolated = 'NEVER_SAY_VIOLATED';
    case PersonalisationSlotsInsufficient = 'PERSONALISATION_SLOTS_INSUFFICIENT';
    case NotBlockedOnRef = 'NOT_BLOCKED_ON_REF';

    /** A dispatch failure: the assertion never executed, so it is not evidence. */
    public function isDispatchFailure(): bool
    {
        return in_array($this, [self::UnknownProperty, self::PropertyNotImplemented, self::InvalidArgument], true);
    }

    /** A dependency the check needed was absent, so nothing was tested. */
    public function isDependencyMissing(): bool
    {
        return in_array($this, [
            self::ValidatorUnavailable,
            self::NoFactsAvailable,
            self::NoProofPointsAvailable,
            self::NoNeverSayRulesAvailable,
        ], true);
    }
}
