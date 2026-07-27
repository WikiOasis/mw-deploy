<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Deployment;
use App\Models\MediaWikiVersion;
use App\Models\Patch;
use App\Models\Repository;
use App\Models\User;
use App\Policies\DeploymentPolicy;
use App\Policies\MediaWikiVersionPolicy;
use App\Policies\PatchPolicy;
use App\Policies\RepositoryPolicy;
use App\Policies\UserPolicy;
use App\Services\Git\Contracts\GitRefProvider;
use App\Services\Git\LocalGitRefProvider;
use App\Services\Git\NullGitRefProvider;
use App\Services\Git\SaltGitRefProvider;
use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\ProcessSaltClient;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class MwDeployServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Deployment::class => DeploymentPolicy::class,
        Repository::class => RepositoryPolicy::class,
        MediaWikiVersion::class => MediaWikiVersionPolicy::class,
        Patch::class => PatchPolicy::class,
        User::class => UserPolicy::class,
    ];

    public function register(): void
    {
        $this->app->singleton(SaltClient::class, ProcessSaltClient::class);

        $this->app->bind(GitRefProvider::class, function (): GitRefProvider {
            return match ((string) config('mwdeploy.git.driver', 'salt')) {
                'salt' => $this->app->make(SaltGitRefProvider::class),
                'local' => $this->app->make(LocalGitRefProvider::class),
                default => new NullGitRefProvider,
            };
        });
    }

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Every permission name is also a gate ability, so Blade can write
        // @can('deploy.force_flag') directly.
        foreach (array_keys(Permissions::all()) as $permission) {
            Gate::define($permission, fn (User $user): bool => $user->hasPermission($permission));
        }
    }
}
