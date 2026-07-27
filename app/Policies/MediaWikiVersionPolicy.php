<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MediaWikiVersion;
use App\Models\User;
use App\Support\Permissions;

final class MediaWikiVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MediaWikiVersion $version): bool
    {
        return true;
    }

    /**
     * Cutting a new version clones a hundred repositories and roughly doubles the
     * tree on staging and every appserver, so it is its own grant.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::VERSIONS_MANAGE);
    }

    /**
     * Removing a version takes down every wiki still pointed at it. The runner
     * also refuses when the farm's wiki → version map says it is in use — this
     * gate is about who may even attempt it.
     */
    public function undeploy(User $user, MediaWikiVersion $version): bool
    {
        return $user->hasPermission(Permissions::UNDEPLOY_VERSION) && $version->isPresent();
    }

    /**
     * Rebuilding a previously removed version is a create, not an undeploy.
     */
    public function restore(User $user, MediaWikiVersion $version): bool
    {
        return $user->hasPermission(Permissions::VERSIONS_MANAGE) && ! $version->isPresent();
    }
}
