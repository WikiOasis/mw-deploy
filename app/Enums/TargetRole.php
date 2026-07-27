<?php

declare(strict_types=1);

namespace App\Enums;

enum TargetRole: string
{
    case Appserver = 'appserver';
    case Proxy = 'proxy';
    case Staging = 'staging';

    public function label(): string
    {
        return match ($this) {
            self::Appserver => 'Application server',
            self::Proxy => 'HAProxy',
            self::Staging => 'Staging',
        };
    }
}
