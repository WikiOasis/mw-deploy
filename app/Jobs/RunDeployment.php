<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Events\DeploymentProgressed;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentAuthorizer;
use App\Services\Deployment\DeploymentRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One queued job per deployment. It blocks for as long as the rsyncs, l10n
 * rebuilds and canary retries take, which is exactly why it is off the
 * request/response cycle. Run the worker with --timeout=0 --tries=1.
 *
 * Deployments must stack, not vanish: dispatching one while another is mid-run
 * has to leave it sitting in the queue for the (single) worker to pick up next,
 * not silently fail to reach the queue at all. That ruled out ShouldBeUnique —
 * its lock is acquired at dispatch time and only released once the job finishes,
 * so every deployment created while one was running would fail to even be
 * queued, and nothing ever retried it afterwards (see ForceFailDeployment's old
 * docblock, and docs/OPERATIONS.md). The staging tree is instead guarded by the
 * lock below, acquired and released inside this one method call so its lifetime
 * never depends on the queue's own timing — it only ever refuses to run if some
 * other worker is genuinely mid-deployment right now, which "only ever run one
 * worker" (docs/OPERATIONS.md) means should not happen outside a
 * misconfiguration.
 */
final class RunDeployment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    /** Shared with ForceFailDeployment, which force-releases it for a crashed worker. */
    public const LOCK_KEY = 'mwdeploy-staging-tree';

    public const LOCK_TTL = 6 * 3600;

    public function __construct(public readonly int $deploymentId) {}

    public function handle(DeploymentRunner $runner, DeploymentAuthorizer $authorizer): void
    {
        $deployment = Deployment::query()
            ->with(['repoRefs.repositoryVersion.repository', 'deploymentPatches.patch', 'creator'])
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

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);

        if (! $lock->get()) {
            // Only reachable with more than one worker processing this queue —
            // "only ever run one worker" (docs/OPERATIONS.md) means this is a
            // misconfiguration alarm, not a normal queueing outcome. Whatever
            // else is queued behind this one is untouched and will get its own
            // turn at the lock.
            $deployment->forceFill([
                'status' => DeploymentStatus::Failed->value,
                'failure_reason' => 'Another deployment appears to be running concurrently against the shared '
                    .'staging tree. This portal supports exactly one queue worker at a time — check for a '
                    .'duplicate worker process before retrying.',
                'finished_at' => now(),
            ])->save();

            DeploymentProgressed::dispatch($deployment);

            Log::error('mwdeploy: could not acquire the staging-tree lock; another worker may be running', [
                'id' => $deployment->getKey(),
            ]);

            return;
        }

        try {
            $runner->run($deployment);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Best-effort: if this job's own run crashed hard enough that the
        // `finally` above never got to run (worker killed, not just an
        // exception), the lock would otherwise sit held until LOCK_TTL lapses.
        // Releasing a lock nobody holds is a no-op, so this is safe to call
        // unconditionally.
        Cache::lock(self::LOCK_KEY)->forceRelease();

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
