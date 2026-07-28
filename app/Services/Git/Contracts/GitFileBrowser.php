<?php

declare(strict_types=1);

namespace App\Services\Git\Contracts;

use App\Models\RepositoryVersion;
use App\Services\Git\GitBlob;
use App\Services\Git\GitTreeEntry;

interface GitFileBrowser
{
    /**
     * Resolve a branch name, tag or abbreviated SHA to the full 40-character
     * commit SHA, so callers (and the cache) always key on content rather than
     * on a name that can move.
     */
    public function resolve(RepositoryVersion $checkout, string $ref): string;

    /**
     * @return list<GitTreeEntry>
     */
    public function tree(RepositoryVersion $checkout, string $sha, string $path): array;

    public function blob(RepositoryVersion $checkout, string $sha, string $path): GitBlob;
}
