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

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Present => 'bg-emerald-100 text-emerald-800 ring-emerald-300',
            self::Undeployed => 'bg-slate-100 text-slate-500 ring-slate-300',
        };
    }

    public function isPresent(): bool
    {
        return $this === self::Present;
    }
}
