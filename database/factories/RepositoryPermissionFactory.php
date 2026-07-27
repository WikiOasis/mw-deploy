<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RepositoryPermission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepositoryPermission>
 */
final class RepositoryPermissionFactory extends Factory
{
    protected $model = RepositoryPermission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [];
    }
}
