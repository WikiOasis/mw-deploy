<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RepositoryType;

/**
 * Where a checkout lives inside the MediaWiki tree.
 *
 * Resolved once at registration and stored on the repository_versions row, so the
 * layout is auditable and a later config change cannot silently repoint an
 * existing checkout at a different directory — which for an undeploy would mean
 * deleting the wrong thing.
 */
final class PathResolver
{
    /**
     * @param  string|null  $configDirectory  where the config repository is checked
     *                                        out, relative to the deploy root. The
     *                                        container passes the configured value;
     *                                        null means the conventional "config",
     *                                        which keeps this class usable without
     *                                        a booted application.
     */
    public function __construct(private readonly ?string $configDirectory = null) {}

    /**
     * Path relative to the MediaWiki root.
     *
     *   core       1.45  → versions/1.45
     *   extension  1.45  → versions/1.45/extensions/Echo
     *   skin       1.45  → versions/1.45/skins/Vector
     *   extension  null  → extensions/Echo          (unversioned, top level)
     *   config     any   → config
     */
    public function relativePath(RepositoryType $type, string $name, ?string $coreVersion): string
    {
        if ($type === RepositoryType::Config) {
            return $this->configDirectory();
        }

        $version = $coreVersion === null || $coreVersion === ''
            ? null
            : $this->sanitiseSegment($coreVersion);

        if ($type === RepositoryType::Core) {
            // Core *is* the version directory, so it cannot be unversioned.
            return 'versions/'.($version ?? '');
        }

        $leaf = ($type->subdirectory() ?? 'extensions').'/'.$this->sanitiseSegment($name);

        return $version === null ? $leaf : 'versions/'.$version.'/'.$leaf;
    }

    /**
     * Where the config repository is checked out, relative to the deploy root.
     *
     * Farms disagree on the name — "config", "mw-config", "wikiconfig" — so it is
     * configurable, and sanitised for the same reason every other segment is: it
     * ends up in an `rm -rf` target.
     */
    public function configDirectory(): string
    {
        $configured = $this->sanitiseSegment((string) ($this->configDirectory ?? 'config'));

        return $configured === '' ? 'config' : $configured;
    }

    /**
     * The version subtree itself, for scaffolding and for undeploying a version.
     */
    public function versionPath(string $coreVersion): string
    {
        return 'versions/'.$this->sanitiseSegment($coreVersion);
    }

    /**
     * Refuse anything that could climb out of the tree or inject shell syntax.
     *
     * The shim quotes its arguments and independently refuses paths outside its
     * configured root, but a path that escapes the staging root is a problem
     * regardless of quoting — and these paths are handed to `rm -rf`.
     */
    public function sanitiseSegment(string $segment): string
    {
        $segment = trim($segment, "/ \t\n\r\0\x0B");

        // Drop everything that is not a plausible directory-name character. This
        // also removes the slashes that would let a name span directories.
        $segment = preg_replace('/[^A-Za-z0-9._\-]/', '', $segment) ?? '';

        // Collapse dot runs so ".." can never survive in any form, then trim
        // leading and trailing punctuation.
        $segment = preg_replace('/\.{2,}/', '.', $segment) ?? '';

        return trim($segment, '.-');
    }

    /**
     * A core version string is a directory name and is used to build delete
     * targets, so it is validated rather than merely sanitised.
     */
    public function isValidVersion(string $version): bool
    {
        return preg_match('/^[0-9]+\.[0-9]+$/', $version) === 1;
    }
}
