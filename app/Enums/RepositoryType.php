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

    /**
     * The permission required to include a repository of this type in a deploy.
     */
    public function deployPermission(): string
    {
        return 'deploy.'.$this->value;
    }

    /**
     * Directory the repository is cloned into, relative to the MediaWiki root
     * (optionally inside a versions/<ver> subtree).
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
}
