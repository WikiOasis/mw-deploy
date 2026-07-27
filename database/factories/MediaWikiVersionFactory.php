<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PresenceStatus;
use App\Models\MediaWikiVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaWikiVersion>
 */
final class MediaWikiVersionFactory extends Factory
{
    protected $model = MediaWikiVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version' => '1.'.fake()->unique()->numberBetween(30, 99),
            'status' => PresenceStatus::Present->value,
            'sort_order' => 0,
        ];
    }

    public function undeployed(): static
    {
        return $this->state([
            'status' => PresenceStatus::Undeployed->value,
            'undeployed_at' => now(),
        ]);
    }
}
