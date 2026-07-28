<?php

declare(strict_types=1);

namespace App\Services\Git\Contracts;

use App\Models\RepositoryVersion;
use App\Services\Git\GitRef;

interface GitRefProvider
{
    /**
     * Remote-tracking branches available for the ref picker, read from this
     * checkout's clone on the staging host.
     *
     * @return list<GitRef>
     */
    public function branches(RepositoryVersion $checkout): array;

    /**
     * Recent commits, most recent first.
     *
     * @return list<GitRef>
     */
    public function commits(RepositoryVersion $checkout, ?string $branch = null): array;

    /**
     * Whether this provider can actually answer, so the UI can explain an empty
     * picker instead of silently showing nothing.
     */
    public function isAvailable(): bool;

    /**
     * Update the remote-tracking refs (git fetch --prune) without listing
     * anything or resetting the working tree. What refresh() calls before
     * re-listing.
     */
    public function fetch(RepositoryVersion $checkout): void;

    /**
     * Force an up-to-date view of this checkout's refs, bypassing whatever
     * caching sits in front of branches()/commits().
     *
     * On the raw (Local/Salt) providers this is just fetch(): they hold no
     * cache of their own, so the next branches()/commits() call is already
     * live. CachedGitRefProvider is where this actually matters — it also
     * re-lists and rewrites the persistent cache rows.
     */
    public function refresh(RepositoryVersion $checkout): void;
}
