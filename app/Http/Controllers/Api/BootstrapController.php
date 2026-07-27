<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\RepositoryType;
use App\Enums\TargetRole;
use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\DeployTarget;
use App\Models\MediaWikiVersion;
use App\Models\Patch;
use App\Models\Repository;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything the single-page app needs before it renders anything: who is signed
 * in, what they may do, and the handful of deployment-wide settings the UI has to
 * agree with the server about (parallelism ceiling, staging host, whether ref
 * discovery works).
 *
 * Served as one request, and also inlined into the app shell so a cold load does
 * not paint an empty chrome while it waits for permissions to arrive.
 */
final class BootstrapController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->user()));
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(?User $user): array
    {
        if ($user === null) {
            return ['authenticated' => false];
        }

        return [
            'authenticated' => true,
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'permissions' => $user->permissionNames(),
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'two_factor_required' => $user->requiresTwoFactor(),
            ],
            'can' => [
                'deploy' => $user->can('create', Deployment::class),
                'undeploy' => $user->hasAnyPermission(Permissions::anyUndeploy()),
                'rollback' => $user->hasPermission(Permissions::DEPLOY_ROLLBACK),
                'decide' => $user->hasPermission(Permissions::DEPLOY_DECIDE),
                'pool' => $user->hasPermission(Permissions::DEPLOY_POOL),
                'force' => $user->hasPermission(Permissions::DEPLOY_FORCE_FLAG),
                'target_production' => $user->hasPermission(Permissions::DEPLOY_PRODUCTION_SERVERS),
                'manage_repositories' => $user->hasPermission(Permissions::REPOSITORIES_MANAGE),
                'manage_versions' => $user->hasPermission(Permissions::VERSIONS_MANAGE),
                'manage_patches' => $user->hasPermission(Permissions::PATCHES_MANAGE),
                'manage_targets' => $user->hasPermission(Permissions::TARGETS_MANAGE),
                'manage_users' => $user->hasPermission(Permissions::USERS_MANAGE),
                'undeploy_version' => $user->hasPermission(Permissions::UNDEPLOY_VERSION),
            ],
            'settings' => [
                'app_name' => (string) config('app.name'),
                'staging_host' => (string) config('mwdeploy.targets.staging'),
                'staging_path' => (string) config('mwdeploy.paths.staging'),
                'production_path' => (string) config('mwdeploy.paths.production'),
                'scan_root' => (string) config('mwdeploy.discovery.scan_root'),
                'config_dir' => (string) config('mwdeploy.paths.config_dir'),
                'wiki_versions_path' => (string) config('mwdeploy.paths.wiki_versions'),
                'wiki_version_check' => (bool) config('mwdeploy.versions.require_wiki_version_check'),
                'default_parallel' => (int) config('mwdeploy.rollout.default_parallel', 1),
                'max_parallel' => (int) config('mwdeploy.rollout.max_parallel', 8),
                'canary_vhost' => (string) config('mwdeploy.rollout.canary_vhost'),
                'l10n_wiki' => (string) config('mwdeploy.rollout.l10n_wiki'),
                'git_driver' => (string) config('mwdeploy.git.driver'),
                'decision_timeout' => (int) config('mwdeploy.decisions.timeout'),
                'decision_timeout_default' => (string) config('mwdeploy.decisions.timeout_default'),
            ],
            'reference' => [
                'repository_types' => array_map(
                    static fn (RepositoryType $type): array => [
                        'value' => $type->value,
                        'label' => $type->label(),
                        'plural_label' => $type->pluralLabel(),
                        'versioned' => $type->isVersioned(),
                        'subdirectory' => $type->subdirectory(),
                    ],
                    RepositoryType::cases(),
                ),
                'target_roles' => array_map(
                    static fn (TargetRole $role): array => [
                        'value' => $role->value,
                        'label' => $role->label(),
                    ],
                    TargetRole::cases(),
                ),
                'permissions' => Permissions::all(),
            ],
            // Counts, so the nav can show whether the portal has been set up at
            // all. An empty registry is the normal state of a fresh install, and
            // the SPA points at the import screen when it sees one.
            'counts' => [
                'repositories' => Repository::query()->active()->count(),
                'versions' => MediaWikiVersion::query()->active()->count(),
                'patches' => Patch::query()->active()->count(),
                'appservers' => DeployTarget::query()->active()->role(TargetRole::Appserver)->count(),
                'proxies' => DeployTarget::query()->active()->role(TargetRole::Proxy)->count(),
                'config_repositories' => Repository::query()->active()->ofType(RepositoryType::Config)->count(),
            ],
        ];
    }
}
