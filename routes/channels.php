<?php

declare(strict_types=1);

use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return (int) $user->id === (int) $id;
});

/**
 * Live dashboard feed. Anyone who may view a deployment may watch it happen; the
 * destructive actions on it are gated separately.
 */
Broadcast::channel('deployments', function (User $user): bool {
    return $user->can('viewAny', Deployment::class);
});

Broadcast::channel('deployments.{deployment}', function (User $user, string $deployment): bool {
    $model = Deployment::find($deployment);

    return $model !== null && $user->can('view', $model);
});
