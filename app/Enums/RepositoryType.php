<?php

declare(strict_types=1);

namespace App\Enums;

enum RepositoryType: string
{
    case Core = 'core';
    case Extension = 'extension';
    case Skin = 'skin';
    case Config = 'config';

    public function label(): string
    {
        return match ($this) {
            self::Core => 'MediaWiki core',
            self::Extension => 'Extension',
            self::Skin => 'Skin',
            self::Config => 'Config',
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Core => 'MediaWiki core',
            self::Extension => 'Extensions',
            self::Skin => 'Skins',
            self::Config => 'Config',
        };
    }

    /**
     * The permission required to include a checkout of this type in a deploy.
     */
    public function deployPermission(): string
    {
        return 'deploy.'.$this->value;
    }

    /**
     * The permission required to *remove* a checkout of this type.
     *
     * Deliberately separate from the deploy permission: being trusted to update
     * an extension is not the same as being trusted to delete it off the whole
     * fleet. Core has no per-type undeploy — removing core means undeploying the
     * whole version, which is gated by deploy.undeploy_version.
     */
    public function undeployPermission(): string
    {
        return match ($this) {
            self::Core => 'deploy.undeploy_version',
            self::Extension => 'deploy.undeploy_extension',
            self::Skin => 'deploy.undeploy_skin',
            self::Config => 'deploy.undeploy_config',
        };
    }

    /**
     * Directory the repository is cloned into, relative to the version root.
     */
    public function subdirectory(): ?string
    {
        return match ($this) {
            self::Core => null,
            self::Extension => 'extensions',
            self::Skin => 'skins',
            self::Config => 'config',
        };
    }

    /**
     * Whether checkouts of this type live inside a versions/<ver> subtree.
     *
     * Config sits outside the version tree — one mw-config serves every version —
     * so it never gets a mediawiki_version_id.
     */
    public function isVersioned(): bool
    {
        return $this !== self::Config;
    }

    /**
     * Types that can be copied into a newly reconstructed core version. Core
     * itself is the version, and config is shared across all of them.
     *
     * @return list<self>
     */
    public static function copiedIntoNewVersions(): array
    {
        return [self::Extension, self::Skin];
    }
}
