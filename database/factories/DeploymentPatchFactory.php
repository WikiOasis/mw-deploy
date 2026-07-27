<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DeploymentPatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeploymentPatch>
 */
final class DeploymentPatchFactory extends Factory
{
    protected $model = DeploymentPatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['applied' => false];
    }
}
