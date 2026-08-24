<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\DeploymentIntent;
use App\Enums\RepoAction;
use App\Models\Deployment;
use App\Models\DeploymentRepoRef;
use App\Models\User;
use App\Support\Permissions;

/**
 * The second half of "check permissions in both places".
 *
 * The wizard hides what a user cannot do; this re-derives the same answer from the
 * persisted deployment so a crafted request that bypassed the form is refused by
 * the job before any Salt call happens.
 *
 * Crucially it checks the *actions*, not the declared intent: a deployment
 * labelled `deploy` whose line items are removals still needs undeploy
 * permission.
 */
final class DeploymentAuthorizer
{
    /**
     * @return string|null a human-readable reason the deployment must not run, or
     *                     null when it is allowed
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

        // Removing a whole core version is its own grant, above and beyond the
        // per-checkout checks below.
        if ($deployment->intent === DeploymentIntent::VersionUndeploy
            && ! $actor->hasPermission(Permissions::UNDEPLOY_VERSION)) {
            return $this->denied($actor, Permissions::UNDEPLOY_VERSION);
        }

        if ($deployment->intent === DeploymentIntent::VersionCreate
            && ! $actor->hasPermission(Permissions::VERSIONS_MANAGE)) {
            return $this->denied($actor, Permissions::VERSIONS_MANAGE);
        }

        // Shipping the staging tree as it stands is not scoped to a repository,
        // so the per-checkout grants below cannot stand in for it.
        if ($deployment->intent === DeploymentIntent::SyncStaging
            && ! $actor->hasPermission(Permissions::DEPLOY_SYNC_STAGING)) {
            return $this->denied($actor, Permissions::DEPLOY_SYNC_STAGING);
        }

        if (($violation = $this->checkLineItems($deployment, $actor)) !== null) {
            return $violation;
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

    private function checkLineItems(Deployment $deployment, User $actor): ?string
    {
        // A whole-version removal legitimately carries no per-checkout line items:
        // the version directory goes in one call. The grant for it is checked
        // above.
        $isWholeVersionRemoval = $deployment->intent === DeploymentIntent::VersionUndeploy;

        foreach ($deployment->repoRefs as $ref) {
            /** @var DeploymentRepoRef $ref */
            $checkout = $ref->repositoryVersion;

            if ($checkout === null) {
                return 'a checkout in this deployment no longer exists';
            }

            $repository = $checkout->repository;

            if ($repository === null) {
                return 'a repository in this deployment no longer exists';
            }

            if ($ref->action === RepoAction::Undeploy) {
                if ($isWholeVersionRemoval) {
                    continue;
                }

                if (! $actor->canUndeployRepository($repository)) {
                    return sprintf('%s may not remove %s', $actor->email, $checkout->displayName());
                }

                continue;
            }

            if (! $actor->canDeployRepository($repository)) {
                return sprintf('%s may not deploy %s', $actor->email, $checkout->displayName());
            }
        }

        return null;
    }

    private function denied(User $actor, string $permission): string
    {
        return sprintf('%s lacks the %s permission', $actor->email, $permission);
    }
}
