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
        'ops' => [
            'description' => 'For members of the ops group',
            'permissions' => [
                P::DEPLOY_CORE, P::DEPLOY_EXTENSION, P::DEPLOY_SKIN, P::DEPLOY_CONFIG,
                P::DEPLOY_PRODUCTION_SERVERS, P::DEPLOY_FORCE_FLAG, P::DEPLOY_ROLLBACK,
                P::DEPLOY_DECIDE, P::DEPLOY_POOL, P::DEPLOY_FORCE_FAIL,
                P::UNDEPLOY_EXTENSION, P::UNDEPLOY_SKIN, P::UNDEPLOY_CONFIG, P::UNDEPLOY_VERSION,
                P::VERSIONS_MANAGE, P::REPOSITORIES_MANAGE, P::PATCHES_MANAGE,
                P::TARGETS_MANAGE, P::USERS_MANAGE,
            ],
        ],
        'mediawiki-admins' => [
            'description' => 'For members of the mediawiki-admins group',
            'permissions' => [
                P::DEPLOY_CORE, P::DEPLOY_EXTENSION, P::DEPLOY_SKIN, P::DEPLOY_CONFIG,
                P::DEPLOY_PRODUCTION_SERVERS, P::DEPLOY_ROLLBACK, P::DEPLOY_DECIDE, P::DEPLOY_POOL,
                P::UNDEPLOY_EXTENSION, P::UNDEPLOY_SKIN,
                P::VERSIONS_MANAGE, P::REPOSITORIES_MANAGE, P::DEPLOY_FORCE_FLAG
            ],
        ],
        'beta' => [
            'description' => 'Can deploy to beta',
            'permissions' => [
                P::DEPLOY_CORE, P::DEPLOY_EXTENSION, P::DEPLOY_SKIN, P::DEPLOY_CONFIG,
                P::DEPLOY_ROLLBACK, P::DEPLOY_DECIDE, P::DEPLOY_FORCE_FLAG
            ],
        ],
        'viewer' => [
            'description' => 'Limited read-only access',
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
