<?php

declare(strict_types=1);

namespace App\Services\Voice;

/**
 * Why POST /api/atlas/speak produced no audio, with the HTTP status the
 * controller answers with and a stable machine reason for the cockpit (which
 * falls back to browser speechSynthesis when the user allows it).
 */
final class AtlasSpeechException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self('Atlas voice is not enabled for this workspace.', 403, 'voice.atlas_speak_off');
    }

    public static function blocked(string $reason): self
    {
        return $reason === 'cost_cap_exceeded'
            ? new self('The voice budget for today is used up.', 402, $reason)
            : new self('Voice is switched off right now.', 403, $reason);
    }

    public static function invalid(string $message): self
    {
        return new self($message, 422, 'invalid_text');
    }

    public static function noPersona(): self
    {
        return new self('No voice persona is configured. Run voice:sync-personas and choose a voice.', 503, 'no_persona');
    }

    public static function upstream(string $message, int $status = 502): self
    {
        return new self($message, $status, 'tts_unavailable');
    }
}
