<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::USERS_MANAGE);
    }

    public function view(User $user, User $target): bool
    {
        return $user->hasPermission(Permissions::USERS_MANAGE) || $user->is($target);
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasPermission(Permissions::USERS_MANAGE);
    }

    public function manageRoles(User $user, User $target): bool
    {
        return $user->hasPermission(Permissions::USERS_MANAGE);
    }
}
