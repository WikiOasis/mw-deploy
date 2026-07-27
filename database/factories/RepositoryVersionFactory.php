<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PresenceStatus;
use App\Enums\RefMode;
use App\Enums\RefType;
use App\Models\MediaWikiVersion;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use App\Support\PathResolver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepositoryVersion>
 */
final class RepositoryVersionFactory extends Factory
{
    protected $model = RepositoryVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'mediawiki_version_id' => MediaWikiVersion::factory(),
            'ref_mode' => RefMode::Pinned->value,
            'tracked_ref_type' => RefType::Branch->value,
            'tracked_ref_value' => 'master',
            'status' => PresenceStatus::Present->value,
            'registered_at' => now(),

            // Closures see the merged attributes, so an overridden repository or
            // version still produces the right path.
            'path' => fn (array $attributes) => (new PathResolver)->relativePath(
                Repository::query()->findOrFail($attributes['repository_id'])->type,
                Repository::query()->findOrFail($attributes['repository_id'])->name,
                $attributes['mediawiki_version_id'] === null
                    ? null
                    : MediaWikiVersion::query()->findOrFail($attributes['mediawiki_version_id'])->version,
            ),
        ];
    }

    /**
     * Convenience: build the checkout for a given repository and version, deriving
     * the path from both.
     */
    public function of(Repository $repository, ?MediaWikiVersion $version): static
    {
        return $this->state([
            'repository_id' => $repository->getKey(),
            'mediawiki_version_id' => $version?->getKey(),
            'path' => (new PathResolver)->relativePath($repository->type, $repository->name, $version?->version),
        ]);
    }

    public function undeployed(): static
    {
        return $this->state([
            'status' => PresenceStatus::Undeployed->value,
            'undeployed_at' => now(),
        ]);
    }

    public function pinnedTo(string $ref): static
    {
        return $this->state([
            'ref_mode' => RefMode::Pinned->value,
            'tracked_ref_type' => RefType::detect($ref)->value,
            'tracked_ref_value' => $ref,
        ]);
    }

    public function followingDefaultBranch(): static
    {
        return $this->state([
            'ref_mode' => RefMode::DefaultBranch->value,
            'tracked_ref_type' => null,
            'tracked_ref_value' => null,
        ]);
    }

    public function floating(): static
    {
        return $this->state([
            'ref_mode' => RefMode::Floating->value,
            'tracked_ref_type' => null,
            'tracked_ref_value' => null,
        ]);
    }
}
