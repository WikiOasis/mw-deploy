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
