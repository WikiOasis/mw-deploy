<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GitRefCacheFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['repository_version_id', 'kind', 'branch', 'payload', 'fetched_at'])]
class GitRefCache extends Model
{
    /** @use HasFactory<GitRefCacheFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    public function repositoryVersion(): BelongsTo
    {
        return $this->belongsTo(RepositoryVersion::class);
    }
}
