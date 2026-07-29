<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Enums\DeploymentStatus;
use App\Enums\StepStatus;
use App\Events\DeploymentProgressed;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The last resort for a deployment the pipeline itself will never resolve: the
 * worker that was running it is gone — crashed, OOM-killed, the process
 * restarted mid-job — and RunDeployment's staging-tree lock outlives it. That
 * lock is released by a `finally` block inside the job, which a worker that
 * dies mid-run never reaches, so it sits held until its TTL lapses. See
 * docs/OPERATIONS.md, "The worker died mid-deployment".
 *
 * This does the two things a healthy worker would eventually have done itself:
 * marks the deployment failed and releases the lock, so whatever is already
 * queued behind it can run on the worker's next poll instead of waiting out
 * the TTL. `RunDeployment::failed()` releases the same lock on its own
 * automated failure path (a crash reclaimed by the queue's retry_after); this
 * is only needed when nothing ever calls that — the deployment stays `running`
 * forever with no job left to fail.
 */
final class ForceFailDeployment
{
    public function __invoke(Deployment $deployment, User $actor): void
    {
        $deployment->forceFill([
            'status' => DeploymentStatus::Failed->value,
            'failure_reason' => sprintf(
                'Force-failed by %s: the deployment appeared stuck, most likely because the worker '
                    .'processing it died before it could finish. See docs/OPERATIONS.md, '
                    .'"The worker died mid-deployment".',
                $actor->email,
            ),
            'finished_at' => now(),
        ])->save();

        $deployment->steps()
            ->whereIn('status', [StepStatus::Pending->value, StepStatus::Running->value])
            ->update(['status' => StepStatus::Skipped->value, 'finished_at' => now()]);

        // The one operation that actually unblocks whatever is queued behind
        // this deployment: without this, the staging-tree lock stays held and
        // the next job in line keeps refusing to run, no matter how many times
        // the worker restarts.
        Cache::lock(RunDeployment::LOCK_KEY)->forceRelease();

        DeploymentProgressed::dispatch($deployment);
    }
}
