<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a checkout decides which ref to deploy when the operator does not say.
 *
 * The pin is a default, never a restriction: any deployment may override it. What
 * the pin buys you is that "deploy Echo to every version" does the right thing
 * per version without the operator having to remember that 1.45 wants REL1_45 and
 * 1.46 wants REL1_46.
 */
enum RefMode: string
{
    case Pinned = 'pinned';
    case DefaultBranch = 'default_branch';
    case Floating = 'floating';

    public function label(): string
    {
        return match ($this) {
            self::Pinned => 'Pinned to a ref',
            self::DefaultBranch => "Repository's default branch",
            self::Floating => 'Chosen each deployment',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Pinned => 'This version always deploys the ref recorded here unless the operator overrides it. The usual choice for a released core version.',
            self::DefaultBranch => "Follows the repository's default branch, so this version moves as the branch moves.",
            self::Floating => 'No default. The operator must pick a ref every time this checkout is deployed.',
        };
    }

    public function requiresTrackedRef(): bool
    {
        return $this === self::Pinned;
    }
}
