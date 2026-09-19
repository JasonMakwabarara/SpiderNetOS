<?php

declare(strict_types=1);

namespace App\Services\Brain;

use App\Models\BrainFile;

/**
 * Which data classes a search may return.
 *
 * `data_class` has been computed from the manifest on every brain write since
 * the brain shipped, stored, returned in API payloads and printed into prompt
 * fences as an attribute — and filtered on by nothing. A label an operator
 * reasonably reads as an access control, and which is not one, is worse than
 * no label at all.
 *
 * This is containment, not the whole policy. The information-flow guard over
 * (principal, purpose, destination, action) is its own piece of work. Here
 * there are two callers and two rules.
 *
 * **Agents** may search the classes their card was declared for, and never
 * `personal`. A declared `personal` path still reaches the run — the prompt
 * builder fences `people/user.md` in by name — but that is the declared,
 * reviewed path. Search is the undeclared one, and it must not widen the
 * classification envelope the card was approved under.
 *
 * **People** may search their own tenant's `personal` files, which is what the
 * manifest means by "visible only to the tenant's own users". `confidential`
 * — finance, reports, the subscriber list — needs admin.
 */
final class BrainVisibility
{
    /** Open to any caller inside the tenant. */
    public const OPEN = [BrainFile::DATA_PUBLIC, BrainFile::DATA_INTERNAL];

    /** Never returned by an agent's search, however the card is declared. */
    public const AGENT_NEVER = [BrainFile::DATA_PERSONAL];

    /**
     * @param  list<string>  $declaredPaths  `brain.requires` ∪ `brain.reads`, section anchors already stripped
     * @return list<string>
     */
    public static function forAgent(array $declaredPaths, BrainManifest $manifest): array
    {
        $classes = self::OPEN;

        foreach ($declaredPaths as $path) {
            $path = trim((string) strtok((string) $path, '#'));
            if ($path === '') {
                continue;
            }
            $class = $manifest->dataClass($path);
            if (in_array($class, self::AGENT_NEVER, true) || in_array($class, $classes, true)) {
                continue;
            }
            $classes[] = $class;
        }

        return array_values($classes);
    }

    /** @return list<string> */
    public static function forPerson(bool $isAdmin): array
    {
        $classes = array_merge(self::OPEN, [BrainFile::DATA_PERSONAL]);

        if ($isAdmin) {
            $classes[] = BrainFile::DATA_CONFIDENTIAL;
        }

        return array_values($classes);
    }
}
