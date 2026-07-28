<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GitFileCacheEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'repository_version_id', 'commit_sha', 'kind', 'path',
    'payload', 'disk_path', 'size', 'truncated', 'binary', 'last_accessed_at',
])]
class GitFileCacheEntry extends Model
{
    /** @use HasFactory<GitFileCacheEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'truncated' => 'boolean',
            'binary' => 'boolean',
            'last_accessed_at' => 'datetime',
        ];
    }

    public function repositoryVersion(): BelongsTo
    {
        return $this->belongsTo(RepositoryVersion::class);
    }

    /**
     * Cheap freshness bump on every cache hit, which is what the pruning job
     * (mwdeploy:prune-git-file-cache) keys its TTL off — accessed recently, kept;
     * untouched for 24h, reclaimed.
     */
    public function touchAccessed(): void
    {
        $this->forceFill(['last_accessed_at' => now()])->save();
    }
}
