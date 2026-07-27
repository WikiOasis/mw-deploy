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

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-slate-100 text-slate-600 ring-slate-300',
            self::Running => 'bg-sky-100 text-sky-800 ring-sky-300',
            self::Done => 'bg-emerald-100 text-emerald-800 ring-emerald-300',
            self::Failed => 'bg-rose-100 text-rose-800 ring-rose-300',
            self::Skipped => 'bg-slate-100 text-slate-500 ring-slate-300',
            self::RolledBack => 'bg-amber-100 text-amber-900 ring-amber-300',
        };
    }
}
