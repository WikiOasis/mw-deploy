<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions as P;
use Illuminate\Database\Seeder;

/**
 * The starting role set. Idempotent, so it is safe to re-run after adding a
 * permission — and re-running is how new permissions reach existing roles.
 *
 * Note what the non-admin roles deliberately lack: `deployer` can push code to
 * every version but cannot delete anything, and only `admin` can remove a core
 * version or set --force.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    /** @var array<string, array{description: string, permissions: list<string>}> */
    private const ROLES = [
        'admin' => [
            'description' => 'Full access, including --force, version removal and user management',
            'permissions' => [
                P::DEPLOY_CORE, P::DEPLOY_EXTENSION, P::DEPLOY_SKIN, P::DEPLOY_CONFIG,
                P::DEPLOY_PRODUCTION_SERVERS, P::DEPLOY_FORCE_FLAG, P::DEPLOY_ROLLBACK,
                P::DEPLOY_DECIDE, P::DEPLOY_POOL, P::DEPLOY_FORCE_FAIL,
                P::UNDEPLOY_EXTENSION, P::UNDEPLOY_SKIN, P::UNDEPLOY_CONFIG, P::UNDEPLOY_VERSION,
                P::VERSIONS_MANAGE, P::REPOSITORIES_MANAGE, P::PATCHES_MANAGE,
                P::TARGETS_MANAGE, P::USERS_MANAGE,
            ],
        ],
        'release-manager' => [
            'description' => 'Cuts new core versions and deploys anything, but cannot remove a version',
            'permissions' => [
                P::DEPLOY_CORE, P::DEPLOY_EXTENSION, P::DEPLOY_SKIN, P::DEPLOY_CONFIG,
                P::DEPLOY_PRODUCTION_SERVERS, P::DEPLOY_ROLLBACK, P::DEPLOY_DECIDE, P::DEPLOY_POOL,
                P::UNDEPLOY_EXTENSION, P::UNDEPLOY_SKIN,
                P::VERSIONS_MANAGE, P::REPOSITORIES_MANAGE,
            ],
        ],
        'deployer' => [
            'description' => 'Can deploy anything to production, but cannot remove anything or use --force',
            'permissions' => [
                P::DEPLOY_CORE, P::DEPLOY_EXTENSION, P::DEPLOY_SKIN, P::DEPLOY_CONFIG,
                P::DEPLOY_PRODUCTION_SERVERS, P::DEPLOY_ROLLBACK, P::DEPLOY_DECIDE, P::DEPLOY_POOL,
            ],
        ],
        'extension-maintainer' => [
            'description' => 'Deploys and removes extensions and skins; scope further with repository_permissions',
            'permissions' => [
                P::DEPLOY_EXTENSION, P::DEPLOY_SKIN,
                P::UNDEPLOY_EXTENSION, P::UNDEPLOY_SKIN,
                P::DEPLOY_PRODUCTION_SERVERS, P::DEPLOY_ROLLBACK,
            ],
        ],
        'responder' => [
            'description' => 'Cannot deploy forward, but can roll back and answer canary prompts',
            'permissions' => [
                P::DEPLOY_ROLLBACK, P::DEPLOY_DECIDE, P::DEPLOY_POOL,
            ],
        ],
        'viewer' => [
            'description' => 'Read-only access to the repo browser, versions and deployment history',
            'permissions' => [],
        ],
    ];

    public function run(): void
    {
        $permissions = [];

        foreach (P::all() as $name => $description) {
            $permissions[$name] = Permission::query()->updateOrCreate(
                ['name' => $name],
                ['description' => $description],
            );
        }

        foreach (self::ROLES as $name => $definition) {
            $role = Role::query()->updateOrCreate(
                ['name' => $name],
                ['description' => $definition['description']],
            );

            $role->permissions()->sync(
                collect($definition['permissions'])
                    ->map(fn (string $permission) => $permissions[$permission]->getKey())
                    ->all(),
            );
        }
    }
}
