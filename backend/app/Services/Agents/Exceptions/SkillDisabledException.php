<?php

declare(strict_types=1);

namespace App\Services\Agents\Exceptions;

class SkillDisabledException extends AgentRuntimeException
{
    public function __construct(string $slug)
    {
        parent::__construct("Skill [{$slug}] is disabled for this tenant; enable it before event or scheduled triggers can run it.", 'skill_disabled', 409, ['skill' => $slug]);
    }
}
