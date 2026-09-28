<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An edge from one skill card to another card (`to_slug`) or to an external
 * reference (`to_ref`: an identity such as richard, a product such as
 * hannah_ai, a brain section brain:brand/voice.md#tone, a principle, or a
 * human role it replaces). Feeds the card's Breaks into / Builds on /
 * Replaces / Hands off to sections and the business map edges.
 */
class SkillRelation extends Model
{
    use HasUuids;

    public const BREAKS_INTO = 'breaks_into';

    public const BUILDS_ON = 'builds_on';

    public const HANDS_OFF_TO = 'hands_off_to';

    public const REPLACES = 'replaces';

    public const RELATIONS = [self::BREAKS_INTO, self::BUILDS_ON, self::HANDS_OFF_TO, self::REPLACES];

    public $timestamps = false;

    protected $fillable = ['from_slug', 'relation', 'to_slug', 'to_ref', 'meta', 'position', 'created_at'];

    protected $casts = [
        'meta' => 'array',
        'position' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->created_at = $model->created_at ?? now();
        });
    }

    public function scopeOfType($query, string $relation)
    {
        return $query->where('relation', $relation);
    }

    /** @return BelongsTo<Skill, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'from_slug', 'slug');
    }

    /** @return BelongsTo<Skill, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'to_slug', 'slug');
    }

    public function isExternal(): bool
    {
        return $this->to_slug === null;
    }
}
