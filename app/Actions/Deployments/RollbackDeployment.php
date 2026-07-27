<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Enums\DeploymentStatus;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\RepoStateSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A rollback is just another deployment (section 6.1).
 *
 * Its repo refs come from the failed deployment's repo_state_snapshots instead
 * of from user input, and it then runs through the exact same pipeline — same
 * git-checkout shim, same rsync, same canary, same per-server rollout. There is
 * no separate rollback code path to maintain.
 */
final class RollbackDeployment
{
    /**
     * @param  list<string>|null  $servers  restrict the rollback to these hosts;
     *                                      null reuses the failed deployment's
     *                                      server list
     * @return Deployment|null null when the failed deployment recorded no usable
     *                         undo point
     */
    public function __invoke(Deployment $failed, ?User $actor = null, ?array $servers = null, bool $dispatch = true): ?Deployment
    {
        $snapshots = $failed->snapshots()
            ->with('repository')
            ->get()
            ->filter(fn (RepoStateSnapshot $snapshot) => $snapshot->isRollbackable());

        if ($snapshots->isEmpty()) {
            Log::warning('mwdeploy: refusing to roll back a deployment with no usable snapshots', [
                'deployment' => $failed->getKey(),
            ]);

            return null;
        }

        // Reuse the original options so the rollback rolls out the same way it
        // rolled forward — same parallelism, same depool/repool, same l10n.
        // --force is deliberately dropped: a rollback must not skip its canary.
        $options = $failed->opts()->withForce(false);

        if ($servers !== null) {
            $options = $options->withServers($servers);
        }

        $rollback = DB::transaction(function () use ($failed, $actor, $options, $snapshots): Deployment {
            $rollback = Deployment::create([
                'created_by' => $actor?->getKey() ?? $failed->created_by,
                'status' => DeploymentStatus::Pending->value,
                'rolls_back_deployment_id' => $failed->getKey(),
                'options' => $options->toArray(),
            ]);

            foreach ($snapshots as $snapshot) {
                $rollback->repoRefs()->create([
                    'repository_id' => $snapshot->repository_id,
                    'ref_type' => $snapshot->previous_ref_type->value,
                    'ref_value' => $snapshot->previous_ref_value,
                ]);
            }

            // Patches that were applied on the way forward are re-applied on the
            // way back: the previous ref is the ref they were validated against,
            // so dropping them here would silently un-patch the farm.
            foreach ($failed->deploymentPatches()->where('applied', true)->get() as $applied) {
                $rollback->deploymentPatches()->create([
                    'patch_id' => $applied->patch_id,
                    'applied' => false,
                ]);
            }

            return $rollback;
        });

        if ($dispatch) {
            RunDeployment::dispatch($rollback->getKey());
        }

        return $rollback;
    }
}
