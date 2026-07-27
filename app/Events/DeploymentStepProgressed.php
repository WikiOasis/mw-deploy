<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\DeploymentStep;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One row of the live dashboard changing. Carries the tail of the log so the
 * frontend can append without refetching the whole step.
 */
final class DeploymentStepProgressed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public DeploymentStep $step) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('deployments.'.$this->step->deployment_id)];
    }

    public function broadcastAs(): string
    {
        return 'deployment.step.progressed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->step->getKey(),
            'deployment_id' => $this->step->deployment_id,
            'host' => $this->step->target_hostname,
            'step_name' => $this->step->step_name->value,
            'label' => $this->step->label(),
            'subject' => $this->step->subject,
            'status' => $this->step->status->value,
            'sequence' => $this->step->sequence,
            'elapsed' => $this->step->elapsedSeconds(),
            'log' => $this->step->log,
        ];
    }
}
