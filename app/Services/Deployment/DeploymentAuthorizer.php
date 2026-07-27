<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Models\Deployment;
use App\Models\User;
use App\Support\Permissions;

/**
 * The second half of "check permissions in both places" (section 3.5.3).
 *
 * The wizard hides what a user cannot do; this re-derives the same answer from
 * the persisted deployment so a crafted request that bypassed the form is
 * refused by the job before any Salt call happens.
 */
final class DeploymentAuthorizer
{
    /**
     * @return string|null a human-readable reason the deployment must not run,
     *                     or null when it is allowed
     */
    public function violationFor(Deployment $deployment): ?string
    {
        $actor = $deployment->creator;

        // Only the automatic rollback path creates deployments without a user.
        if ($actor === null) {
            return $deployment->isRollback()
                ? null
                : 'deployment has no creator, so its permissions cannot be verified';
        }

        $options = $deployment->opts();

        if ($deployment->isRollback()) {
            return $actor->hasPermission(Permissions::DEPLOY_ROLLBACK)
                ? null
                : $this->denied($actor, Permissions::DEPLOY_ROLLBACK);
        }

        foreach ($deployment->repoRefs as $ref) {
            if ($ref->repository === null) {
                return 'a repository in this deployment no longer exists';
            }

            if (! $actor->canDeployRepository($ref->repository)) {
                return sprintf(
                    '%s may not deploy %s',
                    $actor->email,
                    $ref->repository->displayName(),
                );
            }
        }

        if (! $options->stagingOnly && ! $actor->hasPermission(Permissions::DEPLOY_PRODUCTION_SERVERS)) {
            return $this->denied($actor, Permissions::DEPLOY_PRODUCTION_SERVERS);
        }

        if ($options->force && ! $actor->hasPermission(Permissions::DEPLOY_FORCE_FLAG)) {
            return $this->denied($actor, Permissions::DEPLOY_FORCE_FLAG);
        }

        // An account that can change production must have TOTP enrolled.
        if ($actor->requiresTwoFactor() && ! $actor->hasTwoFactorEnabled()) {
            return sprintf('%s must enrol two-factor authentication before deploying', $actor->email);
        }

        return null;
    }

    private function denied(User $actor, string $permission): string
    {
        return sprintf('%s lacks the %s permission', $actor->email, $permission);
    }
}
