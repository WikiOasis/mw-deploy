<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Models\Repository;
use App\Services\Git\Contracts\GitRefProvider;

/**
 * Discovery disabled: the ref picker falls back to free text plus each repo's
 * default branch.
 */
final class NullGitRefProvider implements GitRefProvider
{
    public function branches(Repository $repository): array
    {
        return [new GitRef($repository->default_branch, isDefault: true)];
    }

    public function commits(Repository $repository, ?string $branch = null): array
    {
        return [];
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
