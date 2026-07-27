<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a deployment does to one checkout.
 */
enum RepoAction: string
{
    case Deploy = 'deploy';
    case Undeploy = 'undeploy';

    public function label(): string
    {
        return match ($this) {
            self::Deploy => 'Deploy',
            self::Undeploy => 'Undeploy',
        };
    }

    public function isUndeploy(): bool
    {
        return $this === self::Undeploy;
    }
}
