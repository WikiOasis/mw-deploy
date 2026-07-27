<?php

declare(strict_types=1);

namespace App\Services\Salt;

use App\Enums\StepName;
use InvalidArgumentException;

/**
 * Builds a single `mwdeploy-shim <verb> ...` command line to hand to Salt.
 *
 * The resulting string is executed by a shell on the minion, so every argument
 * is single-quoted here. Callers must not pre-quote.
 */
final class ShimCommand
{
    /** @var list<string> */
    private array $arguments = [];

    private function __construct(
        public readonly StepName $step,
        private readonly string $verb,
    ) {}

    public static function make(StepName $step, ?string $verb = null): self
    {
        return new self($step, $verb ?? $step->value);
    }

    /**
     * `haproxy depool` / `haproxy repool` are two words, unlike every other verb.
     */
    public static function haproxy(StepName $step, string $action): self
    {
        if (! in_array($action, ['depool', 'repool'], true)) {
            throw new InvalidArgumentException("Unknown haproxy action [{$action}].");
        }

        return new self($step, 'haproxy '.$action);
    }

    public function option(string $name, string|int|float $value): self
    {
        $this->arguments[] = '--'.ltrim($name, '-');
        $this->arguments[] = (string) $value;

        return $this;
    }

    /**
     * Add `--name value` only when the value is present, so optional shim flags
     * do not turn into `--vhost ''`.
     */
    public function optionalOption(string $name, string|int|float|null $value): self
    {
        if ($value === null || $value === '') {
            return $this;
        }

        return $this->option($name, $value);
    }

    public function flag(string $name, bool $enabled = true): self
    {
        if ($enabled) {
            $this->arguments[] = '--'.ltrim($name, '-');
        }

        return $this;
    }

    /**
     * @param  list<string>  $values
     */
    public function repeatedOption(string $name, array $values): self
    {
        foreach ($values as $value) {
            $this->option($name, $value);
        }

        return $this;
    }

    /**
     * The command line as the minion's shell will see it.
     */
    public function toString(): string
    {
        $parts = [$this->quote((string) config('mwdeploy.shim.binary'))];

        // The verb may be two words ("haproxy depool"); quote each separately so
        // it stays two argv entries.
        foreach (explode(' ', $this->verb) as $word) {
            $parts[] = $this->quote($word);
        }

        foreach ($this->arguments as $argument) {
            $parts[] = $this->quote($argument);
        }

        return implode(' ', $parts);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * POSIX single-quote escaping: wrap in single quotes and close/escape/reopen
     * around any embedded single quote. Safe for arbitrary bytes.
     */
    private function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
