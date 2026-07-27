<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Patch;
use App\Models\RepositoryVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patch>
 */
final class PatchFactory extends Factory
{
    protected $model = Patch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => $name,
            'description' => fake()->sentence(),
            'target_repository_version_id' => null,
            'target_path' => 'versions/1.45/extensions/Echo',
            'file_path' => str_replace(' ', '-', $name).'.patch',
            'original_filename' => str_replace(' ', '-', $name).'.patch',
            'format' => 'unified',
            'active' => true,
        ];
    }

    /**
     * Deliberately not named for(): that is Illuminate's belongs-to helper on the
     * base Factory, and shadowing it breaks every other factory call.
     */
    public function forCheckout(RepositoryVersion $checkout): static
    {
        return $this->state([
            'target_repository_version_id' => $checkout->getKey(),
            'target_path' => $checkout->path,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
