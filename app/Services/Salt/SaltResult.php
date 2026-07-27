<?php

declare(strict_types=1);

namespace App\Services\Salt;

/**
 * Outcome of one Salt call, after unwrapping Salt's own JSON envelope and the
 * shim's JSON result line.
 */
final readonly class SaltResult
{
    /**
     * @param  array<string, mixed>|null  $payload  the shim's decoded JSON object
     */
    public function __construct(
        public bool $ok,
        public string $target,
        public ?int $retcode,
        public string $stdout = '',
        public string $stderr = '',
        public ?array $payload = null,
        public ?string $error = null,
        public string $rawSaltOutput = '',
        public float $durationSeconds = 0.0,
    ) {}

    /**
     * A step that was never attempted because the deployment had already been
     * aborted. Modelled as a result so the pipeline has one code path.
     */
    public static function aborted(string $target): self
    {
        return new self(
            ok: false,
            target: $target,
            retcode: null,
            error: 'not attempted: deployment aborted',
        );
    }

    public function failed(): bool
    {
        return ! $this->ok;
    }

    /**
     * The shim's own one-line summary when it gave us one, otherwise the best
     * available description of what went wrong.
     */
    public function detail(): string
    {
        if (isset($this->payload['detail']) && is_string($this->payload['detail'])) {
            return $this->payload['detail'];
        }

        if ($this->error !== null && $this->error !== '') {
            return $this->error;
        }

        $fallback = trim($this->stderr) !== '' ? trim($this->stderr) : trim($this->stdout);

        return $fallback === '' ? ($this->ok ? 'ok' : 'failed with no output') : $fallback;
    }

    /**
     * Arbitrary field out of the shim payload, e.g. the `ref` reported back by
     * git-head.
     */
    public function payloadValue(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    /**
     * Multi-line block appended to the step log.
     */
    public function toLog(): string
    {
        $lines = [];

        if ($this->retcode !== null) {
            $lines[] = 'retcode='.$this->retcode;
        }

        foreach (['stdout' => $this->stdout, 'stderr' => $this->stderr] as $label => $stream) {
            $stream = trim($stream);

            if ($stream !== '') {
                $lines[] = $label.':';
                $lines[] = $stream;
            }
        }

        if ($this->error !== null && $this->error !== '') {
            $lines[] = 'error: '.$this->error;
        }

        return implode("\n", $lines);
    }
}
