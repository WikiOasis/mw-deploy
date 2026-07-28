<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Enums\RepositoryType;
use Illuminate\Support\Collection;

/**
 * The result of one read-only pass over a MediaWiki tree.
 */
final readonly class TreeScan
{
    /**
     * @param  Collection<int, ScannedCheckout>  $checkouts
     * @param  list<string>  $versions  core versions found as versions/<ver> directories
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $root,
        public Collection $checkouts,
        public array $versions,
        public array $warnings,
        public ?string $shimVersion = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the shim's decoded JSON result
     */
    public static function fromPayload(string $root, array $payload): self
    {
        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];

        $checkouts = collect($entries)
            ->map(static fn (mixed $entry): ?ScannedCheckout => is_array($entry)
                ? ScannedCheckout::fromPayload($entry)
                : null)
            ->filter()
            ->values();

        return new self(
            root: (string) ($payload['root'] ?? $root),
            checkouts: $checkouts,
            versions: array_values(array_map(
                static fn (mixed $version): string => (string) $version,
                is_array($payload['versions'] ?? null) ? $payload['versions'] : [],
            )),
            warnings: array_values(array_map(
                static fn (mixed $warning): string => (string) $warning,
                is_array($payload['warnings'] ?? null) ? $payload['warnings'] : [],
            )),
            shimVersion: is_string($payload['shim_version'] ?? null) ? $payload['shim_version'] : null,
        );
    }

    /**
     * The inverse of fromPayload() — a plain array of strings/scalars a cache
     * store can always faithfully round-trip.
     *
     * `config('cache.serializable_classes')` is `false` in this app (a Laravel
     * security default: it stops a poisoned cache entry from instantiating
     * arbitrary objects), which means every real cache backend — database,
     * file, redis, anything but the in-memory array store the tests use —
     * quietly turns a cached TreeScan back into `__PHP_Incomplete_Class` on
     * read, not a real TreeScan. `instanceof TreeScan` then always fails, so
     * nothing may ever cache the object itself; round-tripping through this
     * array plus fromPayload() is the only shape that survives the trip.
     *
     * @return array<string, mixed>
     */
    public function toCacheArray(): array
    {
        return [
            'root' => $this->root,
            'entries' => $this->checkouts->map(
                static fn (ScannedCheckout $checkout): array => $checkout->toEntryArray(),
            )->all(),
            'versions' => $this->versions,
            'warnings' => $this->warnings,
            'shim_version' => $this->shimVersion,
        ];
    }

    /**
     * @return Collection<int, ScannedCheckout>
     */
    public function ofType(RepositoryType $type): Collection
    {
        return $this->checkouts->filter(
            static fn (ScannedCheckout $checkout): bool => $checkout->type === $type
        )->values();
    }

    public function find(string $path): ?ScannedCheckout
    {
        return $this->checkouts->firstWhere('path', trim($path, '/'));
    }

    /**
     * The config checkout, if the tree has one. There is at most one: config sits
     * outside the version trees because one of it serves every version.
     */
    public function config(): ?ScannedCheckout
    {
        return $this->ofType(RepositoryType::Config)->first();
    }

    /**
     * Core version reported by MW_VERSION for a given versions/<ver> tree.
     */
    public function coreVersionFor(string $version): ?string
    {
        return $this->ofType(RepositoryType::Core)
            ->firstWhere('version', $version)
            ?->coreVersion;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->checkouts
            ->groupBy(static fn (ScannedCheckout $checkout): string => $checkout->type->value)
            ->map(static fn (Collection $group): int => $group->count())
            ->all();
    }
}
