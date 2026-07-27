<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\StepStatus;
use App\Events\DeploymentStepProgressed;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Services\Salt\SaltCall;
use App\Services\Salt\SaltResult;

/**
 * Writes the row-per-server-per-step model the curses dashboard used to keep in
 * /var/log/mwdeploy-state.json, and pushes each change out over Echo.
 */
final class StepRecorder
{
    private int $sequence = 0;

    public function __construct(private readonly Deployment $deployment) {}

    /**
     * Record that a call has started. Returns the step row so the caller can
     * finish it once the Salt process exits.
     */
    public function begin(SaltCall $call): DeploymentStep
    {
        $step = $this->deployment->steps()->create([
            'target_hostname' => $call->target,
            'step_name' => $call->step()->value,
            'subject' => $call->subject,
            'status' => StepStatus::Running->value,
            'command' => $call->describe(),
            'sequence' => ++$this->sequence,
            'started_at' => now(),
        ]);

        $this->broadcast($step);

        return $step;
    }

    public function finish(DeploymentStep $step, SaltResult $result, ?StepStatus $status = null): DeploymentStep
    {
        $step->status = $status ?? ($result->ok ? StepStatus::Done : StepStatus::Failed);
        $step->finished_at = now();
        $step->log = trim(($step->log ?? '')."\n".$result->toLog());
        $step->save();

        $this->broadcast($step);

        return $step;
    }

    /**
     * Record a step we deliberately did not run, e.g. everything after an abort.
     */
    public function skip(SaltCall $call, string $why): DeploymentStep
    {
        $step = $this->deployment->steps()->create([
            'target_hostname' => $call->target,
            'step_name' => $call->step()->value,
            'subject' => $call->subject,
            'status' => StepStatus::Skipped->value,
            'command' => $call->describe(),
            'log' => 'skipped: '.$why,
            'sequence' => ++$this->sequence,
        ]);

        $this->broadcast($step);

        return $step;
    }

    public function note(DeploymentStep $step, string $line): void
    {
        $step->appendLog($line);

        $this->broadcast($step);
    }

    /**
     * Mark a completed step as reverted by a rollback, so history shows which
     * work was undone rather than silently leaving it green.
     */
    public function markRolledBack(string $hostname): void
    {
        $this->deployment->steps()
            ->where('target_hostname', $hostname)
            ->where('status', StepStatus::Done->value)
            ->update(['status' => StepStatus::RolledBack->value]);
    }

    private function broadcast(DeploymentStep $step): void
    {
        DeploymentStepProgressed::dispatch($step);
    }
}
