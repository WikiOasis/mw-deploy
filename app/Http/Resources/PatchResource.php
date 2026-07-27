<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Patch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Patch
 */
final class PatchResource extends JsonResource
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
            'description' => $this->description,
            'format' => $this->format,
            'active' => $this->active,
            'freeform' => $this->isFreeform(),
            'target_checkout_id' => $this->target_repository_version_id,
            'target_label' => $this->targetLabel(),
            'target_path' => $this->target_path,
            'original_filename' => $this->original_filename,
            'staging_target_path' => $this->stagingTargetPath(),
            'shim_patch_path' => $this->shimPatchPath(),
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),

            // The last dry run's verdict. Surfaced because a patch that stopped
            // applying is something to find out about before a deploy, not during.
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_check_ok' => $this->last_check_ok,
            'last_check_detail' => $this->last_check_detail,

            'can' => [
                'manage' => $user?->can('update', $this->resource) ?? false,
                'check' => $user?->can('check', $this->resource) ?? false,
            ],
        ];
    }
}
