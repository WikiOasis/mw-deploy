<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RefType;
use App\Models\RepoStateSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepoStateSnapshot>
 */
final class RepoStateSnapshotFactory extends Factory
{
    protected $model = RepoStateSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'previous_present' => true,
            'previous_ref_type' => RefType::Commit->value,
            'previous_ref_value' => fake()->sha1(),
            'new_present' => true,
            'new_ref_type' => RefType::Branch->value,
            'new_ref_value' => 'master',
        ];
    }

    /** A snapshot taken when git-head failed: nothing to roll back to. */
    public function withoutUndoPoint(): static
    {
        return $this->state([
            'previous_present' => true,
            'previous_ref_type' => null,
            'previous_ref_value' => null,
        ]);
    }

    /** The checkout was not on disk beforehand, so rolling back removes it. */
    public function previouslyAbsent(): static
    {
        return $this->state([
            'previous_present' => false,
            'previous_ref_type' => null,
            'previous_ref_value' => null,
        ]);
    }

    /** This deployment removed the checkout. */
    public function removed(): static
    {
        return $this->state([
            'new_present' => false,
            'new_ref_type' => null,
            'new_ref_value' => null,
        ]);
    }
}
