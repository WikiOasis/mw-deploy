<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DeploymentStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DeploymentStep
 */
final class StepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'host' => $this->target_hostname,
            'step_name' => $this->step_name->value,
            'label' => $this->label(),
            'subject' => $this->subject,
            'status' => $this->status->value,
            'status_tone' => $this->status->badgeTone(),
            'icon' => $this->status->icon(),
            'sequence' => $this->sequence,
            'elapsed' => $this->elapsedSeconds(),
            // The exact argv that ran, which is the audit trail — shown collapsed
            // in the UI, but never hidden from it.
            'command' => $this->command,
            'log' => $this->log,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
