<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GitFileCacheEntry;
use App\Models\RepositoryVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GitFileCacheEntry>
 */
final class GitFileCacheEntryFactory extends Factory
{
    protected $model = GitFileCacheEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_version_id' => RepositoryVersion::factory(),
            'commit_sha' => fake()->sha1(),
            'kind' => 'tree',
            'path' => '',
            'payload' => [],
            'disk_path' => null,
            'size' => 0,
            'truncated' => false,
            'binary' => false,
            'last_accessed_at' => now(),
        ];
    }
}
