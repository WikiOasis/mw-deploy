<?php

declare(strict_types=1);

namespace App\Apps;

use App\Models\User;
use App\Support\Permissions;

/**
 * The parts of an app that are the same for every app: how its access
 * permission is spelled, how access is decided, and how it is described to the
 * client.
 *
 * A concrete app supplies its identity and its route file and inherits the rest.
 */
abstract class BaseApp implements ConsoleApp
{
    public function accessPermission(): string
    {
        return Permissions::accessFor($this->id());
    }

    /**
     * @return array<string, string>
     */
    public function permissions(): array
    {
        return Permissions::forApp($this->id());
    }

    public function routeFile(): ?string
    {
        return null;
    }

    public function isEnabled(): bool
    {
        return ! in_array($this->id(), (array) config('console.disabled_apps', []), true);
    }

    /**
     * Two ways in, deliberately.
     *
     * The access permission is the explicit grant: it lets someone read an app
     * without being able to change anything through it, which is what the
     * `viewer` role holds. Holding any of the app's *own* permissions also
     * implies access, so granting `deploy.core` is enough on its own — a grant
     * that silently does nothing until a second grant is paired with it is a
     * permission model people get wrong at 2am.
     */
    public function accessibleBy(User $user): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        return $user->hasAnyPermission(array_keys($this->permissions()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?User $user = null): array
    {
        $granted = $user === null
            ? []
            : array_values(array_intersect(array_keys($this->permissions()), $user->permissionNames()));

        return [
            'id' => $this->id(),
            'name' => $this->name(),
            'summary' => $this->summary(),
            'icon' => $this->icon(),
            'path' => $this->path(),
            'accessible' => $user !== null && $this->accessibleBy($user),
            // What this account holds inside the app, so a screen can explain
            // "you can open this but only to read" without a second round trip.
            'granted' => $granted,
            'permission_count' => count($this->permissions()),
        ];
    }
}
