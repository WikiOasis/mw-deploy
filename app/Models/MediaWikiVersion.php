<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PresenceStatus;
use App\Enums\RepositoryType;
use Database\Factories\MediaWikiVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['version', 'status', 'created_from_id', 'created_by', 'sort_order', 'undeployed_at'])]
class MediaWikiVersion extends Model
{
    /** @use HasFactory<MediaWikiVersionFactory> */
    use HasFactory;

    /**
     * Laravel would infer `media_wiki_versions` from the class name; the table is
     * `mediawiki_versions`, matching how MediaWiki spells itself.
     */
    protected $table = 'mediawiki_versions';

    protected function casts(): array
    {
        return [
            'status' => PresenceStatus::class,
            'undeployed_at' => 'datetime',
        ];
    }

    public function checkouts(): HasMany
    {
        return $this->hasMany(RepositoryVersion::class, 'mediawiki_version_id');
    }

    public function createdFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_from_id');
    }

    public function derivedVersions(): HasMany
    {
        return $this->hasMany(self::class, 'created_from_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class, 'mediawiki_version_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', PresenceStatus::Present->value);
    }

    /**
     * Absolute path of this version's tree inside the staging root.
     */
    public function stagingPath(): string
    {
        return rtrim((string) config('mwdeploy.paths.staging'), '/').'/versions/'.$this->version;
    }

    public function relativePath(): string
    {
        return 'versions/'.$this->version;
    }

    public function isPresent(): bool
    {
        return $this->status === PresenceStatus::Present;
    }

    /**
     * Checkouts of the given types that are currently on disk in this version.
     * This is the set copied forward when reconstructing a new version.
     *
     * @param  list<RepositoryType>  $types
     * @return Collection<int, RepositoryVersion>
     */
    public function presentCheckouts(array $types = []): Collection
    {
        return $this->checkouts()
            ->present()
            ->with('repository')
            ->get()
            ->when(
                $types !== [],
                fn (Collection $checkouts) => $checkouts->filter(
                    fn (RepositoryVersion $checkout) => in_array($checkout->repository->type, $types, true)
                )->values(),
            );
    }
}
