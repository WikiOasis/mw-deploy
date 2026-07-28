<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Enums\RefType;
use App\Enums\RepositoryType;

/**
 * One directory `mwdeploy-shim tree-scan` found in the MediaWiki tree.
 *
 * A faithful record of what is on disk, with no registry opinions folded in: the
 * planner is what decides whether this becomes a repository, updates one, or is
 * reported as something to look at. Keeping the two apart means a scan can be
 * re-planned without re-reading the farm.
 */
final readonly class ScannedCheckout
{
    /**
     * @param  string  $path  relative to the scanned root, e.g. versions/1.45/extensions/Echo
     * @param  array<string, string>  $manifest  extension.json / skin.json fields
     */
    public function __construct(
        public RepositoryType $type,
        public string $name,
        public string $path,
        public ?string $version,
        public bool $isGit,
        public ?string $gitUrl = null,
        public ?RefType $refType = null,
        public ?string $ref = null,
        public ?string $commit = null,
        public ?string $branch = null,
        public ?string $defaultBranch = null,
        public ?string $upstream = null,
        public bool $hasSubmodules = false,
        public array $manifest = [],
        public ?string $coreVersion = null,
    ) {}

    /**
     * Build from one entry of the shim's JSON payload.
     *
     * Unknown kinds are rejected rather than coerced: a shim newer than the portal
     * may report something this version has no model for, and guessing at it would
     * put a row in the registry that nothing knows how to deploy.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function fromPayload(array $entry): ?self
    {
        $type = RepositoryType::tryFrom((string) ($entry['kind'] ?? ''));
        $path = trim((string) ($entry['path'] ?? ''), '/');
        $name = trim((string) ($entry['name'] ?? ''));

        if ($type === null || $path === '' || $name === '') {
            return null;
        }

        $git = is_array($entry['git'] ?? null) ? $entry['git'] : [];
        $manifest = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];

        $ref = self::string($git['ref'] ?? null);

        return new self(
            type: $type,
            name: $name,
            path: $path,
            version: self::string($entry['version'] ?? null),
            isGit: (bool) ($entry['is_git'] ?? false),
            gitUrl: self::string($git['url'] ?? null),
            refType: $ref === null
                ? null
                : (RefType::tryFrom((string) ($git['ref_type'] ?? '')) ?? RefType::detect($ref)),
            ref: $ref,
            commit: self::string($git['commit'] ?? null),
            branch: self::string($git['branch'] ?? null),
            defaultBranch: self::string($git['default_branch'] ?? null),
            upstream: self::string($git['upstream'] ?? null),
            hasSubmodules: (bool) ($git['has_submodules'] ?? false),
            manifest: array_map(
                static fn (mixed $value): string => (string) $value,
                array_filter($manifest, static fn (mixed $value): bool => is_scalar($value)),
            ),
            coreVersion: self::string($entry['core_version'] ?? null),
        );
    }

    /**
     * The inverse of fromPayload() — one entry in the shape TreeScan::toCacheArray()
     * needs so a scan can round-trip through a plain array rather than through PHP's
     * object (un)serialization.
     *
     * @return array<string, mixed>
     */
    public function toEntryArray(): array
    {
        return [
            'kind' => $this->type->value,
            'name' => $this->name,
            'path' => $this->path,
            'version' => $this->version,
            'is_git' => $this->isGit,
            'core_version' => $this->coreVersion,
            'git' => $this->isGit ? [
                'url' => $this->gitUrl,
                'ref_type' => $this->refType?->value,
                'ref' => $this->ref,
                'commit' => $this->commit,
                'branch' => $this->branch,
                'default_branch' => $this->defaultBranch,
                'upstream' => $this->upstream,
                'has_submodules' => $this->hasSubmodules,
            ] : [],
            'meta' => $this->manifest,
        ];
    }

    /**
     * Stable identifier for this entry across a scan → review → apply round trip.
     *
     * The path, because that is what is unique on disk and what the operator is
     * looking at. Ids would not survive a re-scan, and name+version is ambiguous
     * for an extension checked out both top-level and inside a version.
     */
    public function key(): string
    {
        return $this->path;
    }

    /**
     * The branch a newly registered repository should treat as its default.
     *
     * Preference order matters: the remote's own HEAD is the real answer, the
     * branch this checkout is on is a good guess, and 'master' is the fallback
     * MediaWiki repositories overwhelmingly still use.
     */
    public function inferredDefaultBranch(): string
    {
        return $this->defaultBranch
            ?? ($this->upstream === null ? null : substr($this->upstream, (int) strpos($this->upstream, '/') + 1))
            ?? $this->branch
            ?? 'master';
    }

    /**
     * What the extension calls itself, when that differs from its directory.
     */
    public function manifestName(): ?string
    {
        $declared = $this->manifest['name'] ?? null;

        return $declared !== null && $declared !== '' && $declared !== $this->name ? $declared : null;
    }

    public function isImportable(): bool
    {
        return $this->isGit && $this->gitUrl !== null && $this->ref !== null;
    }

    /**
     * Why this entry cannot become a registry row, if it cannot.
     */
    public function blocker(): ?string
    {
        if (! $this->isGit) {
            return 'not a git checkout, so there is no remote to deploy it from';
        }

        if ($this->gitUrl === null) {
            return 'a git checkout with no remote configured';
        }

        if ($this->ref === null) {
            return 'a git checkout with an unreadable HEAD';
        }

        return null;
    }

    private static function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
