<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Enums\DeploymentStatus;
use App\Enums\StepStatus;
use App\Events\DeploymentProgressed;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;

/**
 * The last resort for a deployment the pipeline itself will never resolve: the
 * worker that was running it is gone — crashed, OOM-killed, the process
 * restarted mid-job — and RunDeployment's fleet-wide ShouldBeUnique lock
 * outlives it. That lock is acquired at dispatch time and only released when a
 * worker actually finishes processing the job, so a worker that dies without
 * going through Laravel's normal completion path leaves it held indefinitely:
 * every deployment created afterwards silently fails to even reach the queue
 * (PendingDispatch::shouldDispatch() just returns false, no error, no job row)
 * and sits at Pending forever. See docs/OPERATIONS.md, "The worker died
 * mid-deployment".
 *
 * This does the two things a healthy worker would eventually have done itself:
 * marks the deployment failed and releases the lock. It does not coordinate
 * with anything still running — by the time this is worth calling, there is
 * nothing left to coordinate with. The abandoned queue job row is left alone:
 * once the lock is free, the worker's own retry_after handling reclaims and
 * fails it out on its next poll without any help from here.
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

        // The one operation that actually unblocks every later deployment: without
        // this, the fleet-wide lock stays held and nothing queued afterwards ever
        // reaches the jobs table, no matter how many times the worker restarts.
        Cache::lock(UniqueLock::getKey(new RunDeployment($deployment->getKey())))->forceRelease();

        DeploymentProgressed::dispatch($deployment);
    }
}
