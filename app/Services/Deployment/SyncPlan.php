<?php

declare(strict_types=1);

namespace App\Services\Deployment;

/**
 * What the rsync steps of a deployment should cover.
 *
 * Modelled explicitly because "no paths" is genuinely ambiguous: for a core
 * version bump it means *the whole tree*, and for a pure undeploy it means *do
 * not rsync at all*. Conflating the two would turn removing one extension into a
 * full-fleet tree walk.
 */
final readonly class SyncPlan
{
    /**
     * @param  list<string>  $paths
     */
    private function __construct(
        public bool $required,
        public bool $fullTree,
        public array $paths,
    ) {}

    /** Nothing to sync — every action in this deployment was a removal. */
    public static function none(): self
    {
        return new self(false, false, []);
    }

    /** Sync everything, e.g. a new or bumped core version. */
    public static function fullTree(): self
    {
        return new self(true, true, []);
    }

    /**
     * Sync only these paths, relative to the MediaWiki root.
     *
     * @param  list<string>  $paths
     */
    public static function restrictedTo(array $paths): self
    {
        $paths = array_values(array_unique(array_filter($paths)));

        return $paths === [] ? self::none() : new self(true, false, $paths);
    }

    /**
     * The path list to hand to the shim: empty means "whole tree" there.
     *
     * @return list<string>
     */
    public function shimPaths(): array
    {
        return $this->fullTree ? [] : $this->paths;
    }

    public function describe(): string
    {
        if (! $this->required) {
            return 'no sync (removals only)';
        }

        return $this->fullTree ? 'full tree' : implode(', ', $this->paths);
    }
}
