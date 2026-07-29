<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the operator set out to do.
 *
 * Every intent runs through the same pipeline; this drives permission checks and
 * how history reads, not control flow. The actual work is in
 * deployment_repo_refs.action.
 */
enum DeploymentIntent: string
{
    case Deploy = 'deploy';
    case Undeploy = 'undeploy';
    case VersionCreate = 'version_create';
    case VersionUndeploy = 'version_undeploy';

    public function label(): string
    {
        return match ($this) {
            self::Deploy => 'Deploy',
            self::Undeploy => 'Undeploy',
            self::VersionCreate => 'Create core version',
            self::VersionUndeploy => 'Undeploy core version',
        };
    }

    /**
     * Intent is metadata, so it is mostly neutral.
     *
     * Every intent used to get its own colour, which meant a history row carried
     * two coloured pills competing for the same attention — and the one that
     * matters when scanning a list of deployments is the status. What survives is
     * the part that is a warning rather than a label: an intent that removes
     * things still says so, in the colour the rest of the console uses for that.
     */
    public function badgeTone(): string
    {
        return match ($this) {
            self::Deploy, self::VersionCreate => 'neutral',
            self::Undeploy => 'warning',
            self::VersionUndeploy => 'danger',
        };
    }

    /**
     * Whether this intent removes things, and so needs an undeploy permission
     * rather than a deploy one.
     */
    public function isRemoval(): bool
    {
        return $this === self::Undeploy || $this === self::VersionUndeploy;
    }

    /**
     * Whether refs submitted under this intent should be undeploy actions.
     */
    public function defaultAction(): RepoAction
    {
        return $this->isRemoval() ? RepoAction::Undeploy : RepoAction::Deploy;
    }
}
