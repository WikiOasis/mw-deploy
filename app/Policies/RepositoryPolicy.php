<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Repository;
use App\Models\User;
use App\Support\Permissions;

final class RepositoryPolicy
{
    /**
     * The repository browser is read-only browsing for anyone with an account.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Repository $repository): bool
    {
        return true;
    }

    /**
     * Adding a repository means arbitrary code will run on every appserver, so it
     * is a separate trust decision from "can deploy".
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::REPOSITORIES_MANAGE);
    }

    public function update(User $user, Repository $repository): bool
    {
        return $user->hasPermission(Permissions::REPOSITORIES_MANAGE);
    }

    public function delete(User $user, Repository $repository): bool
    {
        return $user->hasPermission(Permissions::REPOSITORIES_MANAGE);
    }

    public function deploy(User $user, Repository $repository): bool
    {
        return $user->canDeployRepository($repository);
    }

    /**
     * Removing a checkout off the whole fleet is a different grant from updating
     * it, and per-repository scoping still applies on top.
     */
    public function undeploy(User $user, Repository $repository): bool
    {
        return $user->canUndeployRepository($repository);
    }
}
