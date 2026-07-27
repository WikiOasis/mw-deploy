<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RepositoryType;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'type', 'git_url', 'default_branch', 'core_version',
    'path', 'in_use', 'active', 'created_by', 'registered_at',
])]
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => RepositoryType::class,
            'in_use' => 'boolean',
            'active' => 'boolean',
            'registered_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function patches(): HasMany
    {
        return $this->hasMany(Patch::class, 'target_repo_id');
    }

    public function scopedPermissions(): HasMany
    {
        return $this->hasMany(RepositoryPermission::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * Absolute path of this repository inside the staging tree.
     */
    public function stagingPath(): string
    {
        return rtrim((string) config('mwdeploy.paths.staging'), '/').'/'.ltrim($this->path, '/');
    }

    /**
     * Absolute path of this repository inside the production tree.
     */
    public function productionPath(): string
    {
        return rtrim((string) config('mwdeploy.paths.production'), '/').'/'.ltrim($this->path, '/');
    }

    /**
     * Human label including the core version when the repo lives in a
     * versions/<ver> subtree, since the same extension exists once per version.
     */
    public function displayName(): string
    {
        return $this->core_version === null
            ? $this->name
            : $this->name.' ('.$this->core_version.')';
    }

    public function hasScopedPermissions(): bool
    {
        return $this->scopedPermissions()->exists();
    }
}
