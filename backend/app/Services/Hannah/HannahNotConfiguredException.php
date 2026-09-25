<?php

declare(strict_types=1);

namespace App\Services\Hannah;

/**
 * Hannah AI is reachable in principle but this installation cannot talk to it
 * — no signing key, no base URL, no link. Distinct from a transport failure so
 * the cockpit can say "connect Hannah" rather than "Hannah is down".
 */
class HannahNotConfiguredException extends \RuntimeException {}
