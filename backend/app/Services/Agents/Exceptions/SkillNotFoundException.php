<?php

declare(strict_types=1);

namespace App\Services\Agents\Exceptions;

class SkillNotFoundException extends AgentRuntimeException
{
    public function __construct(string $slug)
    {
        parent::__construct("Skill [{$slug}] is not in the catalogue.", 'skill_not_found', 404, ['skill' => $slug]);
    }
}
