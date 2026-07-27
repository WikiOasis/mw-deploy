<?php

declare(strict_types=1);

namespace App\Services\Salt;

use App\Enums\StepName;

/**
 * One `salt '<target>' cmd.run_all '<shim command>'` invocation.
 *
 * Deliberately one target per call: the portal owns sequencing and never fans
 * out to a glob, so a failure is always attributable to a specific host.
 */
final readonly class SaltCall
{
    public function __construct(
        public string $target,
        public ShimCommand $command,
        public ?int $timeout = null,
        public ?string $subject = null,
    ) {}

    public function step(): StepName
    {
        return $this->command->step;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeout ?? $this->command->step->saltTimeout();
    }

    /**
     * The exact argv the portal will run locally, for the review screen and for
     * the audit trail on deployment_steps.command.
     *
     * @return list<string>
     */
    public function argv(): array
    {
        return array_merge(
            [(string) config('mwdeploy.salt.binary')],
            ['--out=json', '--static', '--timeout='.$this->timeoutSeconds()],
            array_values(array_filter((array) config('mwdeploy.salt.extra_args', []))),
            [$this->target, (string) config('mwdeploy.salt.command_module'), $this->command->toString()],
        );
    }

    /**
     * Human-readable rendering of argv, suitable for the review screen.
     */
    public function describe(): string
    {
        return implode(' ', array_map(
            fn (string $part) => preg_match('/^[A-Za-z0-9_@%+=:,.\/\-]+$/', $part) === 1
                ? $part
                : "'".str_replace("'", "'\\''", $part)."'",
            $this->argv(),
        ));
    }
}
