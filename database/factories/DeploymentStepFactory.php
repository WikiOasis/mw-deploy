<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StepName;
use App\Enums\StepStatus;
use App\Models\DeploymentStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeploymentStep>
 */
final class DeploymentStepFactory extends Factory
{
    protected $model = DeploymentStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'target_hostname' => 'mw-us-east-011',
            'step_name' => StepName::RsyncRemote->value,
            'status' => StepStatus::Done->value,
            'sequence' => 1,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }
}
