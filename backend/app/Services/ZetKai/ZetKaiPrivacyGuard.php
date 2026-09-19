<?php

declare(strict_types=1);

namespace App\Services\ZetKai;

use Illuminate\Support\Facades\Log;

/**
 * The line between a personal vault and a business system.
 *
 * ZetKai is one person's Zettelkasten — "the gathering place for every trace".
 * Its categories include things like "My Wife" and "My Journey", and a
 * category can be marked `local_only`, which in ZetKai means *this never
 * leaves this device*. SpiderNetOS is a multi-tenant business platform with
 * embeddings, reports and a cross-tenant learning pool. Those two things must
 * not touch.
 *
 * The real enforcement belongs on ZetKai's side — an integration token must
 * never be *served* a private note, because a guard on the consumer only
 * works when the consumer is running the code you think it is. That change is
 * `SyncController::changes()` filtering on the category's `local_only` flag,
 * and `AtlasRag`/`RetrievalService` excluding them for integration tokens.
 * Until that ships, `zetkai.enabled` should stay off.
 *
 * This class is the second line: nothing that reaches SpiderNetOS gets filed
 * if it is marked private, even if ZetKai hands it over. Two independent
 * checks, because the cost of this one being wrong is not a bug report — it
 * is somebody's private notes in a business brain.
 */
class ZetKaiPrivacyGuard
{
    /** Any of these on a note or its categories means: do not file it. */
    public const PRIVATE_FLAGS = ['local_only', 'private', 'is_private'];

    /**
     * Should this note be filed into the brain?
     *
     * Fails closed. A note whose categories we cannot read is treated as
     * private, because "we could not tell" and "it is fine" are not the same
     * answer when the subject is someone's personal vault.
     *
     * @param  array<string, mixed>  $note
     * @return array{allowed: bool, reason: string}
     */
    public function allows(array $note): array
    {
        foreach (self::PRIVATE_FLAGS as $flag) {
            if (! empty($note[$flag])) {
                return ['allowed' => false, 'reason' => "the note is marked {$flag}"];
            }
        }

        $categories = $note['categories'] ?? null;

        if ($categories === null) {
            // ZetKai always sends categories with a note. Their absence means
            // we are talking to something we do not understand.
            return ['allowed' => false, 'reason' => 'the note arrived without its categories, so privacy cannot be established'];
        }

        foreach ((array) $categories as $category) {
            if (! is_array($category)) {
                continue;
            }
            foreach (self::PRIVATE_FLAGS as $flag) {
                if (! empty($category[$flag])) {
                    $name = (string) ($category['name'] ?? $category['slug'] ?? 'a category');

                    return ['allowed' => false, 'reason' => "it belongs to {$name}, which is {$flag}"];
                }
            }
        }

        return ['allowed' => true, 'reason' => ''];
    }

    /**
     * Filter a batch, and say out loud how much was withheld.
     *
     * The count is logged deliberately: a silent filter that starts excluding
     * everything looks exactly like a vault with nothing in it.
     *
     * @param  list<array<string, mixed>>  $notes
     * @return array{allowed: list<array<string, mixed>>, excluded: int, reasons: array<string, int>}
     */
    public function filter(array $notes, ?string $tenantId = null): array
    {
        $allowed = [];
        $reasons = [];

        foreach ($notes as $note) {
            if (! is_array($note)) {
                continue;
            }

            $verdict = $this->allows($note);
            if ($verdict['allowed']) {
                $allowed[] = $note;

                continue;
            }

            $reasons[$verdict['reason']] = ($reasons[$verdict['reason']] ?? 0) + 1;
        }

        $excluded = count($notes) - count($allowed);
        if ($excluded > 0) {
            Log::info('zetkai.private_notes_withheld', [
                'tenant_id' => $tenantId,
                'excluded' => $excluded,
                'of' => count($notes),
            ]);
        }

        return ['allowed' => $allowed, 'excluded' => $excluded, 'reasons' => $reasons];
    }

    /**
     * A category SpiderNetOS may write a note into.
     *
     * Writing into a private category would put business output inside the
     * part of the vault its owner marked off.
     *
     * @param  array<string, mixed>  $category
     */
    public function writable(array $category): bool
    {
        foreach (self::PRIVATE_FLAGS as $flag) {
            if (! empty($category[$flag])) {
                return false;
            }
        }

        return true;
    }
}
