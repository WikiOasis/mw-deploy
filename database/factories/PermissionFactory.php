<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
final class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'deploy.'.fake()->unique()->slug(1),
            'description' => fake()->sentence(),
        ];
    }
}
