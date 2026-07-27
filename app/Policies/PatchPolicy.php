<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Patch;
use App\Models\User;
use App\Support\Permissions;

final class PatchPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Patch $patch): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::PATCHES_MANAGE);
    }

    public function update(User $user, Patch $patch): bool
    {
        return $user->hasPermission(Permissions::PATCHES_MANAGE);
    }

    public function delete(User $user, Patch $patch): bool
    {
        return $user->hasPermission(Permissions::PATCHES_MANAGE);
    }

    /**
     * The dry-run "does this still apply?" action touches staging, so it needs
     * the manage permission rather than being open to all readers.
     */
    public function check(User $user, Patch $patch): bool
    {
        return $user->hasPermission(Permissions::PATCHES_MANAGE);
    }
}
