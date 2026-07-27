<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Enums\StepName;
use App\Models\RepositoryVersion;
use App\Services\Git\Contracts\GitRefProvider;
use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\SaltCall;
use App\Services\Salt\ShimCommand;
use Illuminate\Support\Facades\Cache;

/**
 * Asks the staging minion for its branches and commits through the same Salt
 * transport as everything else, so the ref picker works whether or not the
 * staging tree happens to live on the Salt master.
 */
final class SaltGitRefProvider implements GitRefProvider
{
    public function __construct(private readonly SaltClient $salt) {}

    public function isAvailable(): bool
    {
        return true;
    }

    public function branches(RepositoryVersion $checkout): array
    {
        $refs = $this->fetch($checkout, 'branches', null);

        usort($refs, function (GitRef $a, GitRef $b) use ($checkout): int {
            return ($b->value === ($checkout->repository?->default_branch ?? 'master') ? 1 : 0)
                <=> ($a->value === ($checkout->repository?->default_branch ?? 'master') ? 1 : 0);
        });

        return $refs;
    }

    public function commits(RepositoryVersion $checkout, ?string $branch = null): array
    {
        return $this->fetch($checkout, 'commits', $branch ?? ($checkout->repository?->default_branch ?? 'master'));
    }

    /**
     * @return list<GitRef>
     */
    private function fetch(RepositoryVersion $checkout, string $kind, ?string $branch): array
    {
        $cacheKey = 'mwdeploy:refs:salt:'.$checkout->getKey().':'.$kind.':'.($branch ?? '-');

        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::remember($cacheKey, now()->addSeconds(60), function () use ($checkout, $kind, $branch): array {
            $command = ShimCommand::make(StepName::GitRefs)
                ->option('path', $checkout->stagingPath())
                ->option('kind', $kind)
                ->option('limit', (int) config('mwdeploy.git.commit_limit', 30))
                ->optionalOption('branch', $branch);

            $result = $this->salt->run(new SaltCall(
                target: (string) config('mwdeploy.targets.staging'),
                command: $command,
                subject: $checkout->displayName(),
            ));

            if (! $result->ok) {
                return [];
            }

            $refs = $result->payloadValue('refs', []);

            return is_array($refs) ? array_values(array_filter($refs, 'is_array')) : [];
        });

        $refs = [];

        foreach ($rows as $row) {
            $value = (string) ($row['value'] ?? '');

            if ($value === '') {
                continue;
            }

            $refs[] = new GitRef(
                value: $value,
                subject: isset($row['subject']) ? (string) $row['subject'] : null,
                author: isset($row['author']) ? (string) $row['author'] : null,
                date: isset($row['date']) ? (string) $row['date'] : null,
                isDefault: $value === ($checkout->repository?->default_branch ?? 'master'),
            );
        }

        return $refs;
    }
}
