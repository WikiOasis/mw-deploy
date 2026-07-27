<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TargetRole;
use App\Models\DeployTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeployTarget>
 */
final class DeployTargetFactory extends Factory
{
    protected $model = DeployTarget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hostname' => 'mw-us-east-'.fake()->unique()->numberBetween(11, 99),
            'role' => TargetRole::Appserver->value,
            'active' => true,
            'sort_order' => 0,
        ];
    }

    public function proxy(): static
    {
        return $this->state([
            'hostname' => 'proxy-'.fake()->unique()->numberBetween(1, 20),
            'role' => TargetRole::Proxy->value,
            'haproxy_backend' => 'mediawiki',
        ]);
    }

    public function staging(): static
    {
        return $this->state([
            'hostname' => (string) config('mwdeploy.targets.staging'),
            'role' => TargetRole::Staging->value,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
