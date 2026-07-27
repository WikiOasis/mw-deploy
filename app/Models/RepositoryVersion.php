<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PresenceStatus;
use App\Enums\RefMode;
use App\Enums\RefType;
use Database\Factories\RepositoryVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One checkout of one repository in one core version — the thing a deployment
 * actually acts on.
 */
#[Fillable([
    'repository_id', 'mediawiki_version_id', 'path',
    'ref_mode', 'tracked_ref_type', 'tracked_ref_value',
    'status', 'registered_at', 'undeployed_at',
])]
class RepositoryVersion extends Model
{
    /** @use HasFactory<RepositoryVersionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ref_mode' => RefMode::class,
            'tracked_ref_type' => RefType::class,
            'status' => PresenceStatus::class,
            'registered_at' => 'datetime',
            'undeployed_at' => 'datetime',
        ];
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function mediawikiVersion(): BelongsTo
    {
        return $this->belongsTo(MediaWikiVersion::class, 'mediawiki_version_id');
    }

    public function patches(): HasMany
    {
        return $this->hasMany(Patch::class, 'target_repository_version_id');
    }

    public function scopePresent(Builder $query): void
    {
        $query->where('status', PresenceStatus::Present->value);
    }

    public function scopeUndeployed(Builder $query): void
    {
        $query->where('status', PresenceStatus::Undeployed->value);
    }

    public function isPresent(): bool
    {
        return $this->status === PresenceStatus::Present;
    }

    public function stagingPath(): string
    {
        return rtrim((string) config('mwdeploy.paths.staging'), '/').'/'.ltrim($this->path, '/');
    }

    public function productionPath(): string
    {
        return rtrim((string) config('mwdeploy.paths.production'), '/').'/'.ltrim($this->path, '/');
    }

    public function versionLabel(): string
    {
        return $this->mediawikiVersion?->version ?? 'unversioned';
    }

    public function displayName(): string
    {
        $name = $this->repository?->name ?? 'unknown';

        return $this->mediawiki_version_id === null
            ? $name
            : $name.' ('.$this->versionLabel().')';
    }

    /**
     * The ref this checkout deploys when the operator does not override it.
     *
     * Returns null under Floating: there is deliberately no default, and the
     * wizard makes the operator choose rather than guessing on their behalf.
     *
     * @return array{type: RefType, value: string}|null
     */
    public function resolvedRef(): ?array
    {
        return match ($this->ref_mode) {
            RefMode::Pinned => $this->tracked_ref_value === null || $this->tracked_ref_value === ''
                ? null
                : [
                    'type' => $this->tracked_ref_type ?? RefType::detect($this->tracked_ref_value),
                    'value' => $this->tracked_ref_value,
                ],
            RefMode::DefaultBranch => [
                'type' => RefType::Branch,
                'value' => (string) ($this->repository?->default_branch ?? 'master'),
            ],
            RefMode::Floating => null,
        };
    }

    public function resolvedRefValue(): ?string
    {
        return $this->resolvedRef()['value'] ?? null;
    }

    /**
     * Human summary of the pin, for the wizard and the versions screen.
     */
    public function refModeSummary(): string
    {
        return match ($this->ref_mode) {
            RefMode::Pinned => 'pinned to '.($this->tracked_ref_value ?? '(unset)'),
            RefMode::DefaultBranch => 'default branch ('.($this->repository?->default_branch ?? '?').')',
            RefMode::Floating => 'chosen each deployment',
        };
    }

    public function markUndeployed(): void
    {
        $this->forceFill([
            'status' => PresenceStatus::Undeployed->value,
            'undeployed_at' => now(),
        ])->save();
    }

    public function markPresent(): void
    {
        $this->forceFill([
            'status' => PresenceStatus::Present->value,
            'undeployed_at' => null,
            'registered_at' => $this->registered_at ?? now(),
        ])->save();
    }
}
