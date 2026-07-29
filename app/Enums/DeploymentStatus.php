<?php

declare(strict_types=1);

namespace App\Enums;

enum DeploymentStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Aborted = 'aborted';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Done, self::Failed, self::Aborted], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The pill's tone.
     *
     * A name, not a set of Tailwind classes: the console has a light and a dark
     * appearance, and a colour chosen here could only ever describe one of them.
     * The tone maps to a pair of tokens in resources/js/components/StatusBadge.vue,
     * which is also where the icon that goes with it lives — "done" and "failed"
     * have to be tellable apart without colour.
     */
    public function badgeTone(): string
    {
        return match ($this) {
            self::Pending => 'neutral',
            self::Running => 'info',
            self::Done => 'success',
            self::Failed => 'danger',
            self::Aborted => 'warning',
        };
    }
}
