<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a checkout (or a whole core version) is currently on disk.
 *
 * Undeployed rows are kept rather than deleted: past deployments reference them,
 * history has to keep resolving, and keeping the row is what lets an undeploy be
 * undone without registering the repository again from scratch.
 */
enum PresenceStatus: string
{
    case Present = 'present';
    case Undeployed = 'undeployed';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Deployed',
            self::Undeployed => 'Undeployed',
        };
    }

    /** See DeploymentStatus::badgeTone() for why this is a tone and not a colour. */
    public function badgeTone(): string
    {
        return match ($this) {
            self::Present => 'success',
            self::Undeployed => 'neutral',
        };
    }

    public function isPresent(): bool
    {
        return $this === self::Present;
    }
}
