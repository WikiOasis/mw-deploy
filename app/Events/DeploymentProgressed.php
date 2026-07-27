<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Deployment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Deployment-level state change: status transitions and blocking prompts. What
 * the curses refresh loop used to pick up from the JSON state file.
 */
final class DeploymentProgressed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Deployment $deployment) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('deployments.'.$this->deployment->getKey()),
            new PrivateChannel('deployments'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'deployment.progressed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->deployment->getKey(),
            'status' => $this->deployment->status->value,
            'pending_decision' => $this->deployment->pending_decision?->value,
            'pending_decision_context' => $this->deployment->pending_decision_context,
            'awaiting_decision' => $this->deployment->awaitingDecision(),
            'failure_reason' => $this->deployment->failure_reason,
            'started_at' => $this->deployment->started_at?->toIso8601String(),
            'finished_at' => $this->deployment->finished_at?->toIso8601String(),
        ];
    }
}
