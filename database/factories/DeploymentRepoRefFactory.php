<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RefType;
use App\Models\DeploymentRepoRef;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeploymentRepoRef>
 */
final class DeploymentRepoRefFactory extends Factory
{
    protected $model = DeploymentRepoRef::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ref_type' => RefType::Branch->value,
            'ref_value' => 'master',
        ];
    }

    public function commit(string $sha): static
    {
        return $this->state(['ref_type' => RefType::Commit->value, 'ref_value' => $sha]);
    }
}
