<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The full permission vocabulary of the console. Kept as constants so the
 * seeder, the Gate registration, the policies and the screens all agree on the
 * spelling of every permission name.
 *
 * Permissions are grouped by app. A permission either belongs to one of the
 * installed apps — in which case holding it also implies access to that app —
 * or to the console itself, which is where user and access management lives.
 * The grouping is what the access admin screen renders, and what
 * `ConsoleApp::permissions()` returns.
 */
final class Permissions
{
    /**
     * The pseudo-app the console's own permissions belong to: accounts, roles
     * and the grants themselves. Deliberately not a real app — there is no
     * console without it, and it is not something to switch off per install.
     */
    public const CONSOLE = 'console';

    /*
     * -----------------------------------------------------------------------
     * Console: user and access management
     * -----------------------------------------------------------------------
     */

    public const USERS_MANAGE = 'users.manage';

    /**
     * Editing what a role grants, which is how an app's permissions are handed
     * out. Separate from USERS_MANAGE: putting someone into an existing role is
     * a smaller act than redefining what that role may do to the fleet.
     */
    public const ROLES_MANAGE = 'roles.manage';

    /*
     * -----------------------------------------------------------------------
     * Deployments app
     * -----------------------------------------------------------------------
     */

    /** Read access to the deployments app, granting nothing inside it. */
    public const DEPLOYMENTS_ACCESS = 'apps.deployments.access';

    public const DEPLOY_CORE = 'deploy.core';

    public const DEPLOY_EXTENSION = 'deploy.extension';

    public const DEPLOY_SKIN = 'deploy.skin';

    public const DEPLOY_CONFIG = 'deploy.config';

    public const DEPLOY_PRODUCTION_SERVERS = 'deploy.production_servers';

    /**
     * Deploy the staging tree as it stands, without selecting checkouts.
     *
     * Its own grant because it is not scoped to a repository: it ships whatever
     * happens to be on staging — including someone else's half-finished work —
     * so being trusted with one extension is not enough to authorise it.
     */
    public const DEPLOY_SYNC_STAGING = 'deploy.sync_staging';

    public const DEPLOY_FORCE_FLAG = 'deploy.force_flag';

    public const DEPLOY_ROLLBACK = 'deploy.rollback';

    public const DEPLOY_DECIDE = 'deploy.decide';

    public const DEPLOY_POOL = 'deploy.pool';

    /**
     * Force-fails a deployment the pipeline itself will never resolve — a worker
     * that died mid-job, leaving the fleet-wide lock held. Deliberately separate
     * from DEPLOY_DECIDE: abort/cancel work *with* a live worker that is still
     * polling for them, this bypasses the pipeline's own safety checks entirely.
     */
    public const DEPLOY_FORCE_FAIL = 'deploy.force_fail';

    /*
     * Removal is gated separately from deployment throughout. Being trusted to
     * update an extension is not the same as being trusted to delete it off the
     * entire fleet, and undeploying a core version is a different order of
     * consequence again — it takes down every wiki still pointing at it.
     */

    public const UNDEPLOY_EXTENSION = 'deploy.undeploy_extension';

    public const UNDEPLOY_SKIN = 'deploy.undeploy_skin';

    public const UNDEPLOY_CONFIG = 'deploy.undeploy_config';

    public const UNDEPLOY_VERSION = 'deploy.undeploy_version';

    public const VERSIONS_MANAGE = 'versions.manage';

    public const REPOSITORIES_MANAGE = 'repositories.manage';

    public const PATCHES_MANAGE = 'patches.manage';

    public const TARGETS_MANAGE = 'targets.manage';

    /**
     * Which app owns which permission. Every name in all() appears in exactly
     * one group; a test asserts it, because a permission belonging to no app is
     * a permission no screen will ever offer.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        return [
            self::CONSOLE => [
                self::USERS_MANAGE,
                self::ROLES_MANAGE,
            ],
            'deployments' => [
                self::DEPLOYMENTS_ACCESS,
                self::DEPLOY_CORE,
                self::DEPLOY_EXTENSION,
                self::DEPLOY_SKIN,
                self::DEPLOY_CONFIG,
                self::DEPLOY_PRODUCTION_SERVERS,
                self::DEPLOY_SYNC_STAGING,
                self::DEPLOY_FORCE_FLAG,
                self::DEPLOY_ROLLBACK,
                self::DEPLOY_DECIDE,
                self::DEPLOY_POOL,
                self::DEPLOY_FORCE_FAIL,
                self::UNDEPLOY_EXTENSION,
                self::UNDEPLOY_SKIN,
                self::UNDEPLOY_CONFIG,
                self::UNDEPLOY_VERSION,
                self::VERSIONS_MANAGE,
                self::REPOSITORIES_MANAGE,
                self::PATCHES_MANAGE,
                self::TARGETS_MANAGE,
            ],
        ];
    }

    /**
     * How an app's access permission is spelled — the one place that knows the
     * pattern.
     */
    public static function accessFor(string $appId): string
    {
        return 'apps.'.$appId.'.access';
    }

    public static function isAccessPermission(string $permission): bool
    {
        return str_starts_with($permission, 'apps.') && str_ends_with($permission, '.access');
    }

    /**
     * name => human description, used by the seeder and the access admin screen.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            self::USERS_MANAGE => 'Manage accounts, and which roles each account holds',
            self::ROLES_MANAGE => 'Create roles and change which app permissions each role grants',

            self::DEPLOYMENTS_ACCESS => 'Open the Deployments app (read-only on its own)',
            self::DEPLOY_CORE => 'Deploy a MediaWiki core version',
            self::DEPLOY_EXTENSION => 'Deploy extensions',
            self::DEPLOY_SKIN => 'Deploy skins',
            self::DEPLOY_CONFIG => 'Deploy wiki config',
            self::DEPLOY_PRODUCTION_SERVERS => 'Target production appservers (rather than staging-only dry runs)',
            self::DEPLOY_SYNC_STAGING => 'Deploy the staging tree as it stands, without selecting checkouts',
            self::DEPLOY_FORCE_FLAG => 'Set --force, skipping the canary gate',
            self::DEPLOY_ROLLBACK => 'Roll back a past deployment',
            self::DEPLOY_DECIDE => 'Answer a blocking canary-failure prompt on a running deployment',
            self::DEPLOY_POOL => 'Manually depool or repool a server',
            self::DEPLOY_FORCE_FAIL => 'Force-fail a deployment whose worker has died, and release the fleet-wide deploy lock',

            self::UNDEPLOY_EXTENSION => 'Remove an extension checkout from staging and every server',
            self::UNDEPLOY_SKIN => 'Remove a skin checkout from staging and every server',
            self::UNDEPLOY_CONFIG => 'Remove the config checkout from staging and every server',
            self::UNDEPLOY_VERSION => 'Remove an entire MediaWiki core version, with everything in it',

            self::VERSIONS_MANAGE => 'Create a new MediaWiki core version and reconstruct its extensions',
            self::REPOSITORIES_MANAGE => 'Add, edit and register repositories',
            self::PATCHES_MANAGE => 'Add, edit and validate patches',
            self::TARGETS_MANAGE => 'Manage the deploy target inventory',
        ];
    }

    /**
     * One app's slice of the vocabulary, name => description.
     *
     * @return array<string, string>
     */
    public static function forApp(string $app): array
    {
        $names = self::groups()[$app] ?? [];

        return array_intersect_key(self::all(), array_flip($names));
    }

    /**
     * Which app a permission belongs to. Unknown names are treated as the
     * console's own, so a grant left behind by a removed app is still visible on
     * the access screen rather than silently invisible.
     */
    public static function appFor(string $permission): string
    {
        foreach (self::groups() as $app => $names) {
            if (in_array($permission, $names, true)) {
                return $app;
            }
        }

        return self::CONSOLE;
    }

    /**
     * Permissions that make an account capable of changing production, and so
     * require TOTP two-factor.
     *
     * App access permissions are excluded: on their own they grant reading and
     * nothing else, and a read-only account is not worth nagging about a
     * requirement that does not apply to it. Anything not listed here is
     * effectively read-only.
     *
     * @return list<string>
     */
    public static function requiringTwoFactor(): array
    {
        return array_values(array_filter(
            array_keys(self::all()),
            fn (string $permission): bool => ! self::isAccessPermission($permission),
        ));
    }

    /**
     * Permissions that let someone open the deployment wizard at all.
     *
     * @return list<string>
     */
    public static function anyDeploy(): array
    {
        return [
            self::DEPLOY_CORE,
            self::DEPLOY_EXTENSION,
            self::DEPLOY_SKIN,
            self::DEPLOY_CONFIG,
        ];
    }

    /**
     * Permissions that let someone remove something.
     *
     * @return list<string>
     */
    public static function anyUndeploy(): array
    {
        return [
            self::UNDEPLOY_EXTENSION,
            self::UNDEPLOY_SKIN,
            self::UNDEPLOY_CONFIG,
            self::UNDEPLOY_VERSION,
        ];
    }
}
