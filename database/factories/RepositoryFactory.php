<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RepositoryType;
use App\Models\Repository;
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
            'default_branch' => 'master',
            'in_use' => true,
            'active' => true,
            'git_url' => fn (array $attributes) => 'https://github.com/wikioasis/mediawiki-extensions-'.$attributes['name'],
        ];
    }

    public function ofType(RepositoryType $type): static
    {
        return $this->state(['type' => $type->value]);
    }

    public function core(): static
    {
        return $this->state(['type' => RepositoryType::Core->value, 'name' => 'mediawiki']);
    }

    public function skin(): static
    {
        return $this->ofType(RepositoryType::Skin);
    }

    public function config(): static
    {
        return $this->state(['type' => RepositoryType::Config->value, 'name' => 'mw-config']);
    }
}
