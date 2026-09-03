<?php

declare(strict_types=1);

namespace App\Models;

use App\Apps\AppRegistry;
use App\Apps\ConsoleApp;
use App\Enums\RepoAction;
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
     * Whether this account may open one of the console's apps.
     *
     * The app itself decides — see BaseApp::accessibleBy() — because what counts
     * as access is the app's business, not the user model's.
     */
    public function canUseApp(ConsoleApp|string $app): bool
    {
        $definition = $app instanceof ConsoleApp ? $app : app(AppRegistry::class)->find($app);

        return $definition !== null && $definition->accessibleBy($this);
    }

    /**
     * The apps on this account's launcher, id => app.
     *
     * @return array<string, ConsoleApp>
     */
    public function apps(): array
    {
        return app(AppRegistry::class)->availableTo($this);
    }

    /**
     * True when this account can change production and therefore must have TOTP
     * enrolled *here*.
     *
     * Accounts linked to the identity provider are exempt. Second-factor policy
     * is the provider's to enforce for them, and demanding a second,
     * console-local factor on top is asking someone to carry two authenticators
     * for one sign-in. Section 3.5.1 is about there being a second factor at all
     * — not about this application owning it.
     *
     * The exemption follows the account, not the session, and that is a deliberate
     * choice with a cost: a linked account that *also* keeps a local password can
     * be entered by that password without the provider seeing it, and so without
     * whatever MFA the provider enforces. Switching password sign-in off (see
     * OidcSettings::passwordLoginAllowed()) is what closes that, and is the
     * intended configuration for an install that relies on this.
     */
    public function requiresTwoFactor(): bool
    {
        if ($this->usesSingleSignOn()) {
            return false;
        }

        return $this->hasAnyPermission(Permissions::requiringTwoFactor());
    }

    /**
     * Whether this account is linked to the identity provider.
     */
    public function usesSingleSignOn(): bool
    {
        return $this->oidc_subject !== null;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Coarse per-type check plus the optional per-repository scoping.
     *
     * A repository with no repository_permissions rows is governed purely by
     * deploy.<type>; once it has rows, the actor must match one of them.
     */
    public function canDeployRepository(Repository $repository): bool
    {
        return $this->canActOnRepository($repository, RepoAction::Deploy);
    }

    /**
     * Removal is a separate grant from deployment. Per-repository scoping still
     * applies on top: someone scoped to Echo who also holds
     * deploy.undeploy_extension can remove Echo and nothing else.
     */
    public function canUndeployRepository(Repository $repository): bool
    {
        return $this->canActOnRepository($repository, RepoAction::Undeploy);
    }

    public function canActOnRepository(Repository $repository, RepoAction $action): bool
    {
        $type = $repository->type instanceof RepositoryType
            ? $repository->type
            : RepositoryType::from((string) $repository->type);

        $permission = $action === RepoAction::Undeploy
            ? $type->undeployPermission()
            : $type->deployPermission();

        if (! $this->hasPermission($permission)) {
            return false;
        }

        return $this->withinRepositoryScope($repository);
    }

    /**
     * Whether the optional per-repository scoping admits this actor.
     */
    public function withinRepositoryScope(Repository $repository): bool
    {
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
     * Bulk form of canActOnRepository() for list screens: two queries total
     * instead of two per repository.
     *
     * @param  Collection<int, Repository>  $repositories
     * @return Collection<int, Repository>
     */
    public function actionableRepositories(Collection $repositories, RepoAction $action = RepoAction::Deploy): Collection
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
            ->filter(function (Repository $repository) use ($scoped, $allowed, $action): bool {
                $type = $repository->type instanceof RepositoryType
                    ? $repository->type
                    : RepositoryType::from((string) $repository->type);

                $permission = $action === RepoAction::Undeploy
                    ? $type->undeployPermission()
                    : $type->deployPermission();

                if (! $this->hasPermission($permission)) {
                    return false;
                }

                return ! $scoped->contains($repository->getKey())
                    || $allowed->contains($repository->getKey());
            })
            ->values();
    }

    /**
     * @param  Collection<int, Repository>  $repositories
     * @return Collection<int, Repository>
     */
    public function deployableRepositories(Collection $repositories): Collection
    {
        return $this->actionableRepositories($repositories, RepoAction::Deploy);
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionCache = null;
    }
}
