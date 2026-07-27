<?php

declare(strict_types=1);

namespace App\Actions\Deployments;

use App\Enums\DeploymentIntent;
use App\Enums\DeploymentStatus;
use App\Enums\RepoAction;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\RepoStateSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A rollback is just another deployment.
 *
 * Its line items come from the failed deployment's repo_state_snapshots instead
 * of from user input, and it then runs through the exact same pipeline. There is
 * no separate rollback code path.
 *
 * Because a snapshot records *presence* as well as ref, the same logic reverses
 * every intent:
 *
 *   was present at ref X  → deploy X back
 *   was absent            → undeploy it again
 *
 * so undoing an undeploy, undoing a newly added extension, undoing a whole new
 * core version and undoing a plain ref change are one implementation.
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
    public function __invoke(
        Deployment $failed,
        ?User $actor = null,
        ?array $servers = null,
        bool $dispatch = true,
    ): ?Deployment {
        $snapshots = $failed->snapshots()
            ->with('repositoryVersion.repository')
            ->get()
            ->filter(fn (RepoStateSnapshot $snapshot) => $snapshot->isRollbackable()
                && $snapshot->repositoryVersion !== null);

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

        $restoresAnything = $snapshots->contains(
            fn (RepoStateSnapshot $snapshot) => $snapshot->rollbackAction() === RepoAction::Deploy
        );

        $rollback = DB::transaction(function () use ($failed, $actor, $options, $snapshots, $restoresAnything): Deployment {
            $rollback = Deployment::create([
                'created_by' => $actor?->getKey() ?? $failed->created_by,
                'status' => DeploymentStatus::Pending->value,

                // Reversing a version removal restores that version, and vice
                // versa, so the intent flips with it.
                'intent' => $this->reverseIntent($failed, $restoresAnything)->value,
                'mediawiki_version_id' => $failed->mediawiki_version_id,
                'rolls_back_deployment_id' => $failed->getKey(),
                'options' => $options->toArray(),
            ]);

            foreach ($snapshots as $snapshot) {
                $action = $snapshot->rollbackAction();

                $rollback->repoRefs()->create([
                    'repository_version_id' => $snapshot->repository_version_id,
                    'action' => $action->value,
                    'ref_type' => $action === RepoAction::Deploy ? $snapshot->previous_ref_type?->value : null,
                    'ref_value' => $action === RepoAction::Deploy ? $snapshot->previous_ref_value : null,
                ]);
            }

            // Patches that were applied on the way forward are re-applied on the
            // way back: the previous ref is the ref they were validated against,
            // so dropping them here would silently un-patch the farm. Skipped when
            // the rollback only removes things — there is nothing left to patch.
            if ($restoresAnything) {
                foreach ($failed->deploymentPatches()->where('applied', true)->get() as $applied) {
                    $rollback->deploymentPatches()->create([
                        'patch_id' => $applied->patch_id,
                        'applied' => false,
                    ]);
                }
            }

            return $rollback;
        });

        if ($dispatch) {
            RunDeployment::dispatch($rollback->getKey());
        }

        return $rollback;
    }

    /**
     * What undoing this deployment amounts to.
     *
     * A version-create is undone by removing the version; a version-undeploy is
     * undone by rebuilding it. Everything else is an ordinary deploy or undeploy,
     * decided by whether anything is being restored.
     */
    private function reverseIntent(Deployment $failed, bool $restoresAnything): DeploymentIntent
    {
        return match ($failed->intent) {
            DeploymentIntent::VersionCreate => DeploymentIntent::VersionUndeploy,
            DeploymentIntent::VersionUndeploy => DeploymentIntent::VersionCreate,
            default => $restoresAnything ? DeploymentIntent::Deploy : DeploymentIntent::Undeploy,
        };
    }
}
