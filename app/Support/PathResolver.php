<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RepositoryType;

/**
 * Where a repository lives inside the MediaWiki tree.
 *
 * Resolved once at registration and stored on the row, so the layout is
 * auditable and a later config change cannot silently repoint an existing repo
 * at a different directory.
 */
final class PathResolver
{
    /**
     * Path relative to the MediaWiki root.
     *
     * core       versions/1.45
     * extension  versions/1.45/extensions/Echo   (or extensions/Echo, unversioned)
     * skin       versions/1.45/skins/Vector
     * config     config
     */
    public function relativePath(RepositoryType $type, string $name, ?string $coreVersion): string
    {
        $name = $this->sanitiseSegment($name);

        if ($type === RepositoryType::Config) {
            return 'config';
        }

        if ($type === RepositoryType::Core) {
            $version = $this->sanitiseSegment((string) $coreVersion);

            return 'versions/'.$version;
        }

        $subdirectory = $type->subdirectory() ?? 'extensions';

        if ($coreVersion === null || $coreVersion === '') {
            return $subdirectory.'/'.$name;
        }

        return 'versions/'.$this->sanitiseSegment($coreVersion).'/'.$subdirectory.'/'.$name;
    }

    /**
     * Refuse anything that could climb out of the tree or inject shell syntax.
     * The shim quotes its arguments, but a path that escapes the staging root is
     * a problem regardless of quoting.
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
}
