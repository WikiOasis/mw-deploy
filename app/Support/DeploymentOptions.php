<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;

/**
 * The knob set from the wizard's options step, mirroring the CLI flags
 * --parallel / --force / --l10n / --rollout / --servers.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class DeploymentOptions implements Arrayable
{
    /**
     * @param  list<string>  $servers  Salt minion ids of the appservers to roll out to.
     */
    public function __construct(
        public array $servers = [],
        public int $parallel = 1,
        public bool $force = false,
        public bool $l10n = false,
        public bool $rollout = false,
        public bool $stagingOnly = false,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        $servers = $options['servers'] ?? [];

        return new self(
            servers: array_values(array_map('strval', is_array($servers) ? $servers : [])),
            parallel: max(1, (int) ($options['parallel'] ?? 1)),
            force: (bool) ($options['force'] ?? false),
            l10n: (bool) ($options['l10n'] ?? false),
            rollout: (bool) ($options['rollout'] ?? false),
            stagingOnly: (bool) ($options['staging_only'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'servers' => $this->servers,
            'parallel' => $this->parallel,
            'force' => $this->force,
            'l10n' => $this->l10n,
            'rollout' => $this->rollout,
            'staging_only' => $this->stagingOnly,
        ];
    }

    public function withForce(bool $force): self
    {
        return new self(
            servers: $this->servers,
            parallel: $this->parallel,
            force: $force,
            l10n: $this->l10n,
            rollout: $this->rollout,
            stagingOnly: $this->stagingOnly,
        );
    }

    /**
     * @param  list<string>  $servers
     */
    public function withServers(array $servers): self
    {
        return new self(
            servers: array_values($servers),
            parallel: $this->parallel,
            force: $this->force,
            l10n: $this->l10n,
            rollout: $this->rollout,
            stagingOnly: $this->stagingOnly,
        );
    }

    /**
     * @return list<string>
     */
    public function summaryFlags(): array
    {
        $flags = [];

        if ($this->stagingOnly) {
            $flags[] = 'staging only';
        }
        if ($this->rollout) {
            $flags[] = 'rollout (depool/repool)';
        }
        if ($this->l10n) {
            $flags[] = 'l10n rebuild';
        }
        if ($this->force) {
            $flags[] = 'force (ignore canary)';
        }

        $flags[] = 'parallel '.$this->parallel;

        return $flags;
    }
}
