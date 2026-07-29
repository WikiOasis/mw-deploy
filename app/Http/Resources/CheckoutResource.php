<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\RepositoryVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One checkout — a repository in one core version — as the SPA sees it.
 *
 * @mixin RepositoryVersion
 */
final class CheckoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'repository_id' => $this->repository_id,
            'repository_name' => $this->repository?->name,
            'repository_type' => $this->repository?->type->value,
            'version_id' => $this->mediawiki_version_id,
            'version' => $this->mediawikiVersion?->version,
            'version_label' => $this->versionLabel(),
            'display_name' => $this->displayName(),
            'path' => $this->path,
            'staging_path' => $this->stagingPath(),
            'production_path' => $this->productionPath(),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->badgeTone(),
            'present' => $this->isPresent(),

            'ref_mode' => $this->ref_mode->value,
            'ref_mode_label' => $this->ref_mode->label(),
            'ref_mode_summary' => $this->refModeSummary(),
            'tracked_ref_type' => $this->tracked_ref_type?->value,
            'tracked_ref_value' => $this->tracked_ref_value,
            'resolved_ref' => $this->resolvedRefValue(),

            // What the last tree scan saw on disk, kept apart from the pin so the
            // UI can show the two disagreeing rather than picking one.
            'observed_ref' => $this->observedSummary(),
            'observed_ref_value' => $this->observed_ref_value,
            'observed_commit' => $this->observed_commit,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'has_ref_drift' => $this->hasRefDrift(),
            'imported' => $this->discovered_at !== null,

            'registered_at' => $this->registered_at?->toIso8601String(),
            'undeployed_at' => $this->undeployed_at?->toIso8601String(),

            'patch_count' => $this->whenLoaded('patches', fn (): int => $this->patches->count()),
        ];
    }
}
