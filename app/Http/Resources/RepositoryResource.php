<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Repository;
use App\Models\RepositoryVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Repository
 */
final class RepositoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'manifest_name' => $this->manifestName(),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'git_url' => $this->git_url,
            'default_branch' => $this->default_branch,
            'in_use' => $this->in_use,
            'active' => $this->active,
            'versioned' => $this->isVersioned(),
            'imported' => $this->wasImported(),
            'discovered_at' => $this->discovered_at?->toIso8601String(),
            'manifest' => $this->manifest,
            'created_at' => $this->created_at?->toIso8601String(),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),

            'checkouts' => $this->whenLoaded(
                'versions',
                fn () => CheckoutResource::collection(
                    $this->versions
                        ->sortByDesc(fn (RepositoryVersion $checkout): string => $checkout->mediawikiVersion?->version ?? '')
                        ->values()
                ),
            ),

            // Per-repository permission scoping narrows who may act on this one,
            // so the UI has to know both whether it is scoped and what this user
            // may do — a coarse deploy.extension grant is not the whole answer.
            'scoped' => $this->when(
                $this->relationLoaded('scopedPermissions'),
                fn (): bool => $this->scopedPermissions->isNotEmpty(),
            ),
            'can' => [
                'deploy' => $user?->canDeployRepository($this->resource) ?? false,
                'undeploy' => $user?->canUndeployRepository($this->resource) ?? false,
                'manage' => $user?->can('update', $this->resource) ?? false,
            ],
        ];
    }
}
