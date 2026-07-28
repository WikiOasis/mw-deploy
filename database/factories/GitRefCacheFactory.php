<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GitRefCache;
use App\Models\RepositoryVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GitRefCache>
 */
final class GitRefCacheFactory extends Factory
{
    protected $model = GitRefCache::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_version_id' => RepositoryVersion::factory(),
            'kind' => 'branches',
            'branch' => '',
            'payload' => [],
            'fetched_at' => now(),
        ];
    }
}
