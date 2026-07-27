<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The full permission vocabulary from section 3.5.2. Kept as constants so the
 * seeder, the Gate registration, the policies and the Blade views all agree on
 * the spelling of every permission name.
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
            self::DEPLOY_CORE => 'Include a MediaWiki core version in a deployment',
            self::DEPLOY_EXTENSION => 'Include extensions in a deployment',
            self::DEPLOY_SKIN => 'Include skins in a deployment',
            self::DEPLOY_CONFIG => 'Include wiki config in a deployment',
            self::DEPLOY_PRODUCTION_SERVERS => 'Target production appservers (rather than staging-only dry runs)',
            self::DEPLOY_FORCE_FLAG => 'Set --force, skipping the canary gate',
            self::DEPLOY_ROLLBACK => 'Roll back a past deployment',
            self::DEPLOY_DECIDE => 'Answer a blocking canary-failure prompt on a running deployment',
            self::DEPLOY_POOL => 'Manually depool or repool a server',
            self::REPOSITORIES_MANAGE => 'Add, edit and register repositories and core versions',
            self::PATCHES_MANAGE => 'Add, edit and validate patches',
            self::TARGETS_MANAGE => 'Manage the deploy target inventory',
            self::USERS_MANAGE => 'Manage users, roles and permission assignments',
        ];
    }

    /**
     * Permissions that make an account capable of changing production, and so
     * require TOTP two-factor per section 3.5.1. Anything not listed here is
     * effectively read-only.
     *
     * @return list<string>
     */
    public static function requiringTwoFactor(): array
    {
        return [
            self::DEPLOY_CORE,
            self::DEPLOY_EXTENSION,
            self::DEPLOY_SKIN,
            self::DEPLOY_CONFIG,
            self::DEPLOY_PRODUCTION_SERVERS,
            self::DEPLOY_FORCE_FLAG,
            self::DEPLOY_ROLLBACK,
            self::DEPLOY_DECIDE,
            self::DEPLOY_POOL,
            self::REPOSITORIES_MANAGE,
            self::PATCHES_MANAGE,
            self::TARGETS_MANAGE,
            self::USERS_MANAGE,
        ];
    }
}
