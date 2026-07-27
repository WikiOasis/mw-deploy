<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Answers to a blocking canary-failure prompt. Direct analogue of the choices
 * the curses Prompter offered, plus the rollback option the CLI never had.
 */
enum DeploymentDecision: string
{
    case Continue = 'continue';
    case Abort = 'abort';
    case AbortAndRollback = 'abort_and_rollback';

    public function label(): string
    {
        return match ($this) {
            self::Continue => 'Continue anyway',
            self::Abort => 'Abort only',
            self::AbortAndRollback => 'Abort and roll back',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Continue => 'Ignore the canary failure and keep rolling out. Equivalent to --force for the rest of this deployment.',
            self::Abort => 'Stop here. Servers already updated stay on the new ref.',
            self::AbortAndRollback => 'Stop here and immediately deploy the previous ref back to every server this deployment touched.',
        };
    }
}
