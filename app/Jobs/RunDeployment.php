<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Events\DeploymentProgressed;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentAuthorizer;
use App\Services\Deployment\DeploymentRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One queued job per deployment. It blocks for as long as the rsyncs, l10n
 * rebuilds and canary retries take, which is exactly why it is off the
 * request/response cycle. Run the worker with --timeout=0 --tries=1.
 *
 * ShouldBeUnique with a fleet-wide key serialises deployments: staging is a
 * single working tree, so two concurrent deploys would stomp the same checkout
 * (open question 2).
 */
final class RunDeployment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public readonly int $deploymentId) {}

    /**
     * Fleet-wide lock, not per-deployment: the constraint being protected is the
     * shared staging tree.
     */
    public function uniqueId(): string
    {
        return 'mwdeploy-staging-tree';
    }

    public function uniqueFor(): int
    {
        return 6 * 3600;
    }

    public function handle(DeploymentRunner $runner, DeploymentAuthorizer $authorizer): void
    {
        $deployment = Deployment::query()
            ->with(['repoRefs.repository', 'deploymentPatches.patch', 'creator'])
            ->find($this->deploymentId);

        if ($deployment === null) {
            Log::warning('mwdeploy: deployment vanished before its job ran', ['id' => $this->deploymentId]);

            return;
        }

        if ($deployment->status !== DeploymentStatus::Pending) {
            Log::info('mwdeploy: skipping deployment that is not pending', [
                'id' => $deployment->getKey(),
                'status' => $deployment->status->value,
            ]);

            return;
        }

        // Re-check permissions here as well as in the UI: a crafted request that
        // slipped past the form must not be able to deploy anything.
        $violation = $authorizer->violationFor($deployment);

        if ($violation !== null) {
            $deployment->forceFill([
                'status' => DeploymentStatus::Failed->value,
                'failure_reason' => 'Refused: '.$violation,
                'finished_at' => now(),
            ])->save();

            DeploymentProgressed::dispatch($deployment);

            Log::warning('mwdeploy: refused a deployment on permission grounds', [
                'id' => $deployment->getKey(),
                'reason' => $violation,
            ]);

            return;
        }

        $runner->run($deployment);
    }

    public function failed(?Throwable $exception): void
    {
        $deployment = Deployment::find($this->deploymentId);

        if ($deployment === null || $deployment->status->isTerminal()) {
            return;
        }

        $deployment->forceFill([
            'status' => DeploymentStatus::Failed->value,
            'failure_reason' => 'Job failed: '.($exception?->getMessage() ?? 'unknown error'),
            'finished_at' => now(),
        ])->save();

        DeploymentProgressed::dispatch($deployment);
    }
}
