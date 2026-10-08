<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history of a brain file. One row per version; never updated.
 */
class BrainFileVersion extends Model
{
    use HasUuids;

    public const AUTHOR_USER = 'user';

    public const AUTHOR_AGENT = 'agent';

    public const AUTHOR_SYSTEM = 'system';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'brain_file_id', 'version', 'content', 'frontmatter', 'content_hash', 'source',
        'author_type', 'author_ref', 'change_summary', 'created_at',
    ];

    protected $casts = [
        'frontmatter' => 'array',
        'version' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->created_at = $model->created_at ?? now();
        });
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** @return BelongsTo<BrainFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(BrainFile::class, 'brain_file_id');
    }
}
