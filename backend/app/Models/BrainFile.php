<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Head revision of one path in a tenant's Knowledge brain (plan D2).
 * Prose in `content` is the agents' source of truth; structured facts are
 * projected into `frontmatter` from their own tables. History lives in
 * brain_file_versions; writes go through BrainStore (never directly from an
 * agent — agents file brain_proposals).
 */
class BrainFile extends Model
{
    use HasUuids;

    public const SOURCE_HUMAN = 'human';

    public const SOURCE_PROJECTION = 'projection';

    public const SOURCE_AGENT = 'agent';

    public const SOURCES = [self::SOURCE_HUMAN, self::SOURCE_PROJECTION, self::SOURCE_AGENT];

    public const DATA_PUBLIC = 'public';

    public const DATA_INTERNAL = 'internal';

    public const DATA_CONFIDENTIAL = 'confidential';

    public const DATA_PERSONAL = 'personal';

    public const DATA_CLASSES = [self::DATA_PUBLIC, self::DATA_INTERNAL, self::DATA_CONFIDENTIAL, self::DATA_PERSONAL];

    protected $attributes = [
        'source' => self::SOURCE_HUMAN,
        'managed' => false,
        'data_class' => self::DATA_INTERNAL,
        'version' => 1,
    ];

    protected $fillable = [
        'tenant_id', 'path', 'title', 'content', 'frontmatter', 'source', 'managed', 'data_class',
        'version', 'content_hash', 'embedded_version',
    ];

    protected $casts = [
        'frontmatter' => 'array',
        'managed' => 'boolean',
        'version' => 'integer',
        'embedded_version' => 'integer',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Files under a folder prefix, e.g. `people/` or `workspaces/growth/scratch/`. */
    public function scopeUnder($query, string $prefix)
    {
        return $query->where('path', 'like', rtrim($prefix, '/').'/%');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<BrainFileVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(BrainFileVersion::class)->orderByDesc('version');
    }

    /** @return HasMany<BrainProposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(BrainProposal::class);
    }

    public static function hashContent(string $content): string
    {
        return hash('sha256', $content);
    }

    public function isEmbedded(): bool
    {
        return $this->embedded_version !== null && $this->embedded_version >= $this->version;
    }

    public function folder(): string
    {
        $pos = strrpos($this->path, '/');

        return $pos === false ? '' : substr($this->path, 0, $pos);
    }
}
