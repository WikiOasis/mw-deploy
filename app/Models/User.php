<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RepositoryType;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_recovery_codes', 'two_factor_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /** @var list<string>|null memoised permission names */
    private ?array $permissionCache = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Fortify's migration adds this column but declares no cast for it.
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class, 'created_by');
    }

    public function repositoryPermissions(): HasMany
    {
        return $this->hasMany(RepositoryPermission::class);
    }

    /**
     * Every permission name granted to this user through any of their roles.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        return $this->permissionCache = Permission::query()
            ->whereHas('roles.users', fn ($query) => $query->whereKey($this->getKey()))
            ->pluck('name')
            ->map(fn ($name) => (string) $name)
            ->values()
            ->all();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionNames(), true);
    }

    /**
     * @param  list<string>  $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        return array_intersect($permissions, $this->permissionNames()) !== [];
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    /**
     * True when this account can change production and therefore must have TOTP
     * enrolled (section 3.5.1).
     */
    public function requiresTwoFactor(): bool
    {
        return $this->hasAnyPermission(Permissions::requiringTwoFactor());
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Coarse per-type check plus the optional per-repository scoping from
     * section 3.5.2. A repository with no repository_permissions rows is
     * governed purely by deploy.<type>; once it has rows, the actor must match
     * one of them.
     */
    public function canDeployRepository(Repository $repository): bool
    {
        $type = $repository->type instanceof RepositoryType
            ? $repository->type
            : RepositoryType::from((string) $repository->type);

        if (! $this->hasPermission($type->deployPermission())) {
            return false;
        }

        if (! RepositoryPermission::query()->where('repository_id', $repository->getKey())->exists()) {
            return true;
        }

        return RepositoryPermission::query()
            ->where('repository_id', $repository->getKey())
            ->where(function ($query) {
                $query->where('user_id', $this->getKey())
                    ->orWhereIn('role_id', $this->roles()->pluck('roles.id'));
            })
            ->exists();
    }

    /**
     * Bulk form of canDeployRepository() for list screens: two queries total
     * instead of two per repository.
     *
     * @param  Collection<int, Repository>  $repositories
     * @return Collection<int, Repository>
     */
    public function deployableRepositories(Collection $repositories): Collection
    {
        $roleIds = $this->roles()->pluck('roles.id');

        $scoped = RepositoryPermission::query()->pluck('repository_id')->unique();

        $allowed = RepositoryPermission::query()
            ->where(function ($query) use ($roleIds) {
                $query->where('user_id', $this->getKey())->orWhereIn('role_id', $roleIds);
            })
            ->pluck('repository_id')
            ->unique();

        return $repositories
            ->filter(function (Repository $repository) use ($scoped, $allowed): bool {
                $type = $repository->type instanceof RepositoryType
                    ? $repository->type
                    : RepositoryType::from((string) $repository->type);

                if (! $this->hasPermission($type->deployPermission())) {
                    return false;
                }

                return ! $scoped->contains($repository->getKey())
                    || $allowed->contains($repository->getKey());
            })
            ->values();
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionCache = null;
    }
}
