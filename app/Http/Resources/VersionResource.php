<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\RepositoryType;
use App\Models\MediaWikiVersion;
use App\Models\RepositoryVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin MediaWikiVersion
 */
final class VersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->getKey(),
            'version' => $this->version,
            'core_version' => $this->core_version,
            'path' => $this->relativePath(),
            'staging_path' => $this->stagingPath(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->badgeTone(),
            'present' => $this->isPresent(),
            'imported' => $this->discovered_at !== null,
            'created_from' => $this->whenLoaded('createdFrom', fn () => $this->createdFrom?->version),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'undeployed_at' => $this->undeployed_at?->toIso8601String(),

            'checkout_counts' => $this->when(
                $this->relationLoaded('checkouts'),
                fn (): array => $this->countsByType(),
            ),

            'checkouts' => $this->whenLoaded(
                'checkouts',
                fn () => CheckoutResource::collection(
                    $this->checkouts
                        ->sortBy(fn (RepositoryVersion $checkout): string => $checkout->repository?->name ?? '')
                        ->values()
                ),
            ),

            'can' => [
                'undeploy' => $user?->can('undeploy', $this->resource) ?? false,
            ],
        ];
    }

    /**
     * Extensions/skins present in this version, per type, for the version list.
     *
     * @return array<string, int>
     */
    private function countsByType(): array
    {
        $present = $this->checkouts->filter(
            fn (RepositoryVersion $checkout): bool => $checkout->isPresent()
        );

        $counts = [];

        foreach (RepositoryType::cases() as $type) {
            $counts[$type->value] = $present->filter(
                fn (RepositoryVersion $checkout): bool => $checkout->repository?->type === $type
            )->count();
        }

        $counts['total'] = $present->count();

        return $counts;
    }

    /**
     * @param  Collection<int, MediaWikiVersion>  $versions
     * @return array<int, array<string, mixed>>
     */
    public static function options(Collection $versions): array
    {
        return $versions->map(fn (MediaWikiVersion $version): array => [
            'id' => $version->getKey(),
            'version' => $version->version,
            'present' => $version->isPresent(),
        ])->values()->all();
    }
}
