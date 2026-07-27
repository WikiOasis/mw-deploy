<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Support\DeploymentOptions;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deployment>
 */
final class DeploymentFactory extends Factory
{
    protected $model = Deployment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => DeploymentStatus::Pending->value,
            'options' => (new DeploymentOptions)->toArray(),
        ];
    }

    public function withOptions(DeploymentOptions $options): static
    {
        return $this->state(['options' => $options->toArray()]);
    }

    public function status(DeploymentStatus $status): static
    {
        return $this->state(['status' => $status->value]);
    }
}
