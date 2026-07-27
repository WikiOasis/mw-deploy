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

    /** Tailwind classes for the status pill. */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-slate-100 text-slate-700 ring-slate-300',
            self::Running => 'bg-sky-100 text-sky-800 ring-sky-300',
            self::Done => 'bg-emerald-100 text-emerald-800 ring-emerald-300',
            self::Failed => 'bg-rose-100 text-rose-800 ring-rose-300',
            self::Aborted => 'bg-amber-100 text-amber-900 ring-amber-300',
        };
    }
}
