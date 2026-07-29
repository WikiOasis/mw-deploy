<?php

declare(strict_types=1);

namespace App\Enums;

enum StepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case RolledBack = 'rolled_back';

    public function isTerminal(): bool
    {
        return $this !== self::Pending && $this !== self::Running;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pending => '·',
            self::Running => '»',
            self::Done => '✓',
            self::Failed => '✗',
            self::Skipped => '–',
            self::RolledBack => '↩',
        };
    }

    /** See DeploymentStatus::badgeTone() for why this is a tone and not a colour. */
    public function badgeTone(): string
    {
        return match ($this) {
            self::Pending, self::Skipped => 'neutral',
            self::Running => 'info',
            self::Done => 'success',
            self::Failed => 'danger',
            self::RolledBack => 'warning',
        };
    }
}
