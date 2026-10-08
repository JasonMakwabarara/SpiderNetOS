<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Global catalogue row for one skill card (plan D5): a seeded projection of
 * packages/skills/<slug>/card.yaml + prompts/task.md. Tenant state lives in
 * tenant_skills. Keyed by the card's slug.
 */
class Skill extends Model
{
    protected $primaryKey = 'slug';

    public $incrementing = false;

    protected $keyType = 'string';

    public const PILLARS = [
        'sales', 'deals', 'marketing', 'operations', 'intelligence', 'customer', 'back_office', 'people', 'founder',
    ];

    /** Pillar → business_systems.function for the business map. */
    public const PILLAR_FUNCTIONS = [
        'sales' => 'sales',
        'deals' => 'sales',
        'marketing' => 'marketing',
        'operations' => 'operations',
        'customer' => 'operations',
        'intelligence' => 'management',
        'founder' => 'management',
        'back_office' => 'finance',
        'people' => 'recruitment',
    ];

    public const CORE_AGENTS = ['atlas', 'hannah', 'forge', 'sentinel', 'prism', 'nexus'];

    public const RUN_ROUTE = 'route';

    public const RUN_INTERVIEW = 'interview';

    public const RUN_JOB = 'job';

    public const RUN_AGENT = 'agent';

    public const RUN_FLOW = 'flow';

    public const RUN_SERVICE = 'service';

    public const RUN_KINDS = [
        self::RUN_ROUTE, self::RUN_INTERVIEW, self::RUN_JOB, self::RUN_AGENT, self::RUN_FLOW, self::RUN_SERVICE,
    ];

    protected $fillable = [
        'slug', 'version', 'name', 'pillar', 'map_function', 'map_node', 'runs_on', 'core_agent', 'pack_id',
        'card', 'prompt_md', 'card_hash',
    ];

    protected $casts = [
        'card' => 'array',
    ];

    public function scopeInPillar($query, string $pillar)
    {
        return $query->where('pillar', $pillar);
    }

    public function scopeForPack($query, string $packId)
    {
        return $query->where('pack_id', $packId);
    }

    /**
     * Outgoing card edges (named `edges`, not `relations`, because Eloquent's
     * Model already owns a `$relations` property).
     *
     * @return HasMany<SkillRelation, $this>
     */
    public function edges(): HasMany
    {
        return $this->hasMany(SkillRelation::class, 'from_slug', 'slug')->orderBy('position');
    }

    /** @return HasMany<SkillRelation, $this> */
    public function edgesOf(string $relation): HasMany
    {
        return $this->edges()->where('relation', $relation);
    }

    /** @return HasMany<TenantSkill, $this> */
    public function tenantSkills(): HasMany
    {
        return $this->hasMany(TenantSkill::class, 'skill_slug', 'slug');
    }

    /** @return HasMany<AgentRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class, 'skill_slug', 'slug');
    }

    /** Run kind from the card (`run.kind`), defaulting to a runtime run. */
    public function runKind(): string
    {
        return (string) (($this->card ?? [])['run']['kind'] ?? self::RUN_AGENT);
    }

    public function mapFunction(): ?string
    {
        return $this->map_function ?? (self::PILLAR_FUNCTIONS[$this->pillar] ?? null);
    }

    /** @return list<string> tool names the card allows. */
    public function tools(): array
    {
        return array_values((array) (($this->card ?? [])['tools'] ?? []));
    }
}
