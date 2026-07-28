<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\User;
use App\Support\Permissions;

final class DeploymentPolicy
{
    /**
     * Anyone who can sign in can watch deployments; the interesting gates are on
     * the actions, not the reading.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Deployment $deployment): bool
    {
        return true;
    }

    /**
     * Being able to open the wizard at all requires at least one deploy.<type>
     * permission; which repos actually appear is filtered per repository.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyPermission([
            Permissions::DEPLOY_CORE,
            Permissions::DEPLOY_EXTENSION,
            Permissions::DEPLOY_SKIN,
            Permissions::DEPLOY_CONFIG,
        ]);
    }

    /**
     * Deliberately broader than create: when something is broken you want
     * whoever noticed to be able to revert, not to wait for an approver.
     */
    public function rollback(User $user, Deployment $deployment): bool
    {
        if (! $user->hasPermission(Permissions::DEPLOY_ROLLBACK)) {
            return false;
        }

        // Rolling back a rollback is a manual-intervention situation; the
        // automatic path refuses it and so does the button.
        if ($deployment->isRollback()) {
            return false;
        }

        return $deployment->snapshots()->whereNotNull('previous_ref_value')->exists();
    }

    public function decide(User $user, Deployment $deployment): bool
    {
        return $user->hasPermission(Permissions::DEPLOY_DECIDE)
            && $deployment->awaitingDecision();
    }

    public function useForceFlag(User $user): bool
    {
        return $user->hasPermission(Permissions::DEPLOY_FORCE_FLAG);
    }

    public function targetProduction(User $user): bool
    {
        return $user->hasPermission(Permissions::DEPLOY_PRODUCTION_SERVERS);
    }

    /**
     * Manual depool/repool, the web equivalent of the TUI's Ctrl+R menu.
     */
    public function pool(User $user, Deployment $deployment): bool
    {
        return $user->hasPermission(Permissions::DEPLOY_POOL);
    }

    public function abort(User $user, Deployment $deployment): bool
    {
        return $deployment->status === DeploymentStatus::Running
            && $user->hasPermission(Permissions::DEPLOY_DECIDE);
    }

    /**
     * Cancelling a deployment that has not started yet is lower stakes than
     * aborting one mid-flight — nothing has touched staging — but it is gated on
     * the same permission rather than opened to anyone who can queue a deploy, so
     * it stays consistent with the other "control a deployment in flight" actions.
     */
    public function cancel(User $user, Deployment $deployment): bool
    {
        return $deployment->status === DeploymentStatus::Pending
            && $user->hasPermission(Permissions::DEPLOY_DECIDE);
    }

    /**
     * The last resort: a deployment the pipeline itself will never resolve
     * because the worker that was running it is gone. Restricted to
     * DEPLOY_FORCE_FAIL (admin only in the seeder) rather than DEPLOY_DECIDE —
     * unlike abort/cancel, this does not coordinate with a live worker still
     * polling for an answer, it unilaterally declares the deployment over and
     * frees the fleet-wide lock, so misusing it on a deployment that is not
     * actually stuck can corrupt real progress.
     */
    public function forceFail(User $user, Deployment $deployment): bool
    {
        return ! $deployment->status->isTerminal()
            && $user->hasPermission(Permissions::DEPLOY_FORCE_FAIL);
    }
}
