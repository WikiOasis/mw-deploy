<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Models\MediaWikiVersion;
use App\Models\RepositoryVersion;
use App\Services\Salt\SaltCall;
use App\Services\Salt\ShimCalls;

/**
 * The removals a deployment has to perform, expressed so that the same set can
 * be issued against the staging tree and then against each host's production
 * tree.
 *
 * Removing a whole core version is one `rm -rf versions/<ver>` per host rather
 * than one call per checkout inside it: with a hundred extensions that is the
 * difference between one step and a hundred, and the directory goes atomically
 * per host either way. The per-checkout snapshots are still recorded, so the
 * rollback can rebuild it.
 */
final class RemovalPlan
{
    /**
     * @param  list<RepositoryVersion>  $checkouts
     */
    public function __construct(
        private readonly ShimCalls $calls,
        private readonly array $checkouts = [],
        private readonly ?MediaWikiVersion $wholeVersion = null,
    ) {}

    public static function none(ShimCalls $calls): self
    {
        return new self($calls);
    }

    public function isEmpty(): bool
    {
        return $this->checkouts === [] && $this->wholeVersion === null;
    }

    public function removesWholeVersion(): bool
    {
        return $this->wholeVersion !== null;
    }

    /**
     * Removals against the staging tree, plus the staging host's own production
     * copy — the artefact the appservers pull from. Both have to go, or the next
     * sync puts the directory straight back.
     *
     * @return list<SaltCall>
     */
    public function stagingCalls(): array
    {
        $staging = $this->calls->stagingTarget();

        if ($this->wholeVersion !== null) {
            return [
                $this->calls->removeVersion($staging, $this->wholeVersion, fromStaging: true),
                $this->calls->removeVersion($staging, $this->wholeVersion),
            ];
        }

        $calls = [];

        foreach ($this->checkouts as $checkout) {
            $calls[] = $this->calls->removeFromStaging($checkout);
            $calls[] = $this->calls->removeFromProduction($staging, $checkout);
        }

        return $calls;
    }

    /**
     * Removals against one appserver's production tree.
     *
     * @return list<SaltCall>
     */
    public function callsFor(string $hostname): array
    {
        if ($this->wholeVersion !== null) {
            return [$this->calls->removeVersion($hostname, $this->wholeVersion)];
        }

        return array_map(
            fn (RepositoryVersion $checkout) => $this->calls->removeFromProduction($hostname, $checkout),
            $this->checkouts,
        );
    }

    /**
     * @return list<RepositoryVersion>
     */
    public function checkouts(): array
    {
        return $this->checkouts;
    }

    public function describe(): string
    {
        if ($this->wholeVersion !== null) {
            return 'core version '.$this->wholeVersion->version;
        }

        if ($this->checkouts === []) {
            return 'nothing';
        }

        return implode(', ', array_map(
            fn (RepositoryVersion $checkout) => $checkout->displayName(),
            $this->checkouts,
        ));
    }
}
