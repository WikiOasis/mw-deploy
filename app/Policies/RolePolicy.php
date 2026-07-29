<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;

/**
 * Who may redefine what a role grants.
 *
 * Note what this does *not* try to prevent: someone holding roles.manage can
 * grant themselves anything, because they can already put themselves into any
 * existing role. It is an administrative permission, and the console treats it
 * as one rather than pretending to contain it.
 */
final class RolePolicy
{
    public function viewAny(User $user): bool
    {
        // The access screen lists roles for anyone who administers accounts;
        // changing what they grant is the narrower permission below.
        return $user->hasAnyPermission([Permissions::USERS_MANAGE, Permissions::ROLES_MANAGE]);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ROLES_MANAGE);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermission(Permissions::ROLES_MANAGE);
    }
}
