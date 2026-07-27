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
}
