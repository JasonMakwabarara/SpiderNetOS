<?php

declare(strict_types=1);

namespace App\Services\Voice;

/**
 * A named persona cannot speak: unknown, inactive, a cloned voice without
 * complete consent, or no voice id yet (a Voice Design candidate not promoted).
 */
final class VoicePersonaUnavailable extends \RuntimeException
{
    public const UNKNOWN = 'unknown_persona';

    public const INACTIVE = 'inactive';

    public const CONSENT_INCOMPLETE = 'consent_incomplete';

    public const NO_VOICE = 'no_voice_id';

    private const MESSAGES = [
        self::UNKNOWN => 'There is no voice called "%s".',
        self::INACTIVE => 'The voice "%s" is not offered any more.',
        self::CONSENT_INCOMPLETE => 'The voice "%s" is a cloned voice without complete consent (subject name, consent document and date), so Atlas will not use it.',
        self::NO_VOICE => 'The voice "%s" has no provider voice yet.',
    ];

    public function __construct(
        public readonly string $slug,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(self::MESSAGES[$reason] ?? 'The voice "%s" cannot be used.', $slug));
    }
}
