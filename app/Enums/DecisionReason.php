<?php

declare(strict_types=1);

namespace App\Enums;

enum DecisionReason: string
{
    case StagingCanaryFailed = 'staging_canary_failed';
    case ServerCanaryFailed = 'server_canary_failed';

    public function label(): string
    {
        return match ($this) {
            self::StagingCanaryFailed => 'Canary check failed on staging',
            self::ServerCanaryFailed => 'Canary check failed on an appserver',
        };
    }
}
