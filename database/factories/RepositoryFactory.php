<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RepositoryType;
use App\Models\Repository;
use App\Support\PathResolver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
final class RepositoryFactory extends Factory
{
    protected $model = Repository::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->regexify('[A-Z][a-z]{4,10}'),
            'type' => RepositoryType::Extension->value,
            'core_version' => '1.45',
            'default_branch' => 'master',
            'in_use' => true,
            'active' => true,
            'registered_at' => now(),

            // Closures see the merged attributes, so an overridden name or type
            // still produces the right staging path.
            'git_url' => fn (array $attributes) => 'https://github.com/wikioasis/mediawiki-extensions-'.$attributes['name'],
            'path' => fn (array $attributes) => (new PathResolver)->relativePath(
                RepositoryType::from((string) $attributes['type']),
                (string) $attributes['name'],
                $attributes['core_version'] === null ? null : (string) $attributes['core_version'],
            ),
        ];
    }

    public function ofType(RepositoryType $type, ?string $coreVersion = '1.45'): static
    {
        return $this->state([
            'type' => $type->value,
            'core_version' => $type === RepositoryType::Config ? null : $coreVersion,
        ]);
    }

    public function core(string $version = '1.45'): static
    {
        return $this->ofType(RepositoryType::Core, $version)->state(['name' => 'mediawiki']);
    }

    public function config(): static
    {
        return $this->ofType(RepositoryType::Config, null)->state(['name' => 'mw-config']);
    }
}
