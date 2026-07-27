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
use Illuminate\Support\Collection;

/**
 * A repository as a logical thing — "the Echo extension" — not one checkout of
 * it. The per-version checkouts are RepositoryVersion rows.
 */
#[Fillable([
    'name', 'type', 'git_url', 'default_branch', 'in_use', 'active', 'created_by',
    'discovered_at', 'manifest',
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
            'discovered_at' => 'datetime',
            'manifest' => 'array',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RepositoryVersion::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopedPermissions(): HasMany
    {
        return $this->hasMany(RepositoryPermission::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    public function scopeOfType(Builder $query, RepositoryType $type): void
    {
        $query->where('type', $type->value);
    }

    public function displayName(): string
    {
        return $this->name;
    }

    /**
     * The extension's own name for itself, out of extension.json, when it differs
     * from the directory the farm keeps it in — "Notifications" for extensions/Echo.
     */
    public function manifestName(): ?string
    {
        $declared = $this->manifest['name'] ?? null;

        return is_string($declared) && $declared !== '' && $declared !== $this->name ? $declared : null;
    }

    public function wasImported(): bool
    {
        return $this->discovered_at !== null;
    }

    /**
     * Checkouts currently on disk, newest core version first.
     *
     * @return Collection<int, RepositoryVersion>
     */
    public function presentVersions(): Collection
    {
        return $this->versions()
            ->present()
            ->with('mediawikiVersion')
            ->get()
            ->sortByDesc(fn (RepositoryVersion $checkout) => $checkout->mediawikiVersion?->version ?? '')
            ->values();
    }

    public function checkoutFor(?MediaWikiVersion $version): ?RepositoryVersion
    {
        return $this->versions()
            ->where('mediawiki_version_id', $version?->getKey())
            ->first();
    }

    public function hasScopedPermissions(): bool
    {
        return $this->scopedPermissions()->exists();
    }

    /**
     * Whether this repository is checked out in a versions/<ver> subtree at all.
     * Config is not, and neither is an extension deliberately kept top-level.
     */
    public function isVersioned(): bool
    {
        return $this->type->isVersioned();
    }
}
