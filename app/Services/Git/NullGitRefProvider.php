<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Models\RepositoryVersion;
use App\Services\Git\Contracts\GitRefProvider;

/**
 * Discovery disabled: the ref picker falls back to free text plus each repo's
 * default branch.
 */
final class NullGitRefProvider implements GitRefProvider
{
    public function branches(RepositoryVersion $checkout): array
    {
        $branch = $checkout->repository?->default_branch ?? 'master';

        return [new GitRef($branch, isDefault: true)];
    }

    public function commits(RepositoryVersion $checkout, ?string $branch = null): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function fetch(RepositoryVersion $checkout): void
    {
        // Discovery is off; there is nothing to fetch.
    }

    public function refresh(RepositoryVersion $checkout): void
    {
        // Discovery is off; there is nothing to refresh.
    }
}
