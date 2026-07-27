<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The full permission vocabulary. Kept as constants so the seeder, the Gate
 * registration, the policies and the Blade views all agree on the spelling of
 * every permission name.
 */
final class Permissions
{
    public const DEPLOY_CORE = 'deploy.core';

    public const DEPLOY_EXTENSION = 'deploy.extension';

    public const DEPLOY_SKIN = 'deploy.skin';

    public const DEPLOY_CONFIG = 'deploy.config';

    public const DEPLOY_PRODUCTION_SERVERS = 'deploy.production_servers';

    public const DEPLOY_FORCE_FLAG = 'deploy.force_flag';

    public const DEPLOY_ROLLBACK = 'deploy.rollback';

    public const DEPLOY_DECIDE = 'deploy.decide';

    public const DEPLOY_POOL = 'deploy.pool';

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

    public const USERS_MANAGE = 'users.manage';

    /**
     * name => human description, used by the seeder and the users admin screen.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            self::DEPLOY_CORE => 'Deploy a MediaWiki core version',
            self::DEPLOY_EXTENSION => 'Deploy extensions',
            self::DEPLOY_SKIN => 'Deploy skins',
            self::DEPLOY_CONFIG => 'Deploy wiki config',
            self::DEPLOY_PRODUCTION_SERVERS => 'Target production appservers (rather than staging-only dry runs)',
            self::DEPLOY_FORCE_FLAG => 'Set --force, skipping the canary gate',
            self::DEPLOY_ROLLBACK => 'Roll back a past deployment',
            self::DEPLOY_DECIDE => 'Answer a blocking canary-failure prompt on a running deployment',
            self::DEPLOY_POOL => 'Manually depool or repool a server',

            self::UNDEPLOY_EXTENSION => 'Remove an extension checkout from staging and every server',
            self::UNDEPLOY_SKIN => 'Remove a skin checkout from staging and every server',
            self::UNDEPLOY_CONFIG => 'Remove the config checkout from staging and every server',
            self::UNDEPLOY_VERSION => 'Remove an entire MediaWiki core version, with everything in it',

            self::VERSIONS_MANAGE => 'Create a new MediaWiki core version and reconstruct its extensions',
            self::REPOSITORIES_MANAGE => 'Add, edit and register repositories',
            self::PATCHES_MANAGE => 'Add, edit and validate patches',
            self::TARGETS_MANAGE => 'Manage the deploy target inventory',
            self::USERS_MANAGE => 'Manage users, roles and permission assignments',
        ];
    }

    /**
     * Permissions that make an account capable of changing production, and so
     * require TOTP two-factor. Anything not listed here is effectively read-only.
     *
     * @return list<string>
     */
    public static function requiringTwoFactor(): array
    {
        return array_keys(self::all());
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
