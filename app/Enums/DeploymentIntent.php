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

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Deploy => 'bg-sky-100 text-sky-800 ring-sky-300',
            self::Undeploy => 'bg-orange-100 text-orange-900 ring-orange-300',
            self::VersionCreate => 'bg-violet-100 text-violet-900 ring-violet-300',
            self::VersionUndeploy => 'bg-rose-100 text-rose-900 ring-rose-300',
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
