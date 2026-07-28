<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Models\GitRefCache;
use App\Models\RepositoryVersion;
use App\Services\Git\Contracts\GitRefProvider;

/**
 * Makes branch/commit listings a real persistent cache instead of the 60-second
 * Cache::remember the raw providers used to lean on.
 *
 * Once a git_ref_caches row exists it is authoritative — there is no TTL to
 * silently go stale against. The only way to see new refs is refresh(), which
 * is what the "Fetch latest" control in the UI calls. The one exception is the
 * very first time a checkout is viewed: with nothing cached yet, an empty
 * picker would be a worse first impression than one live round-trip.
 */
final class CachedGitRefProvider implements GitRefProvider
{
    public function __construct(private readonly GitRefProvider $raw) {}

    public function isAvailable(): bool
    {
        return $this->raw->isAvailable();
    }

    public function fetch(RepositoryVersion $checkout): void
    {
        $this->raw->fetch($checkout);
    }

    public function branches(RepositoryVersion $checkout): array
    {
        $cache = $this->find($checkout, 'branches', '');

        if ($cache !== null) {
            return $this->decode($cache->payload);
        }

        $refs = $this->raw->branches($checkout);
        $this->write($checkout, 'branches', '', $refs);

        return $refs;
    }

    public function commits(RepositoryVersion $checkout, ?string $branch = null): array
    {
        $key = $branch ?? ($checkout->repository?->default_branch ?? 'master');
        $cache = $this->find($checkout, 'commits', $key);

        if ($cache !== null) {
            return $this->decode($cache->payload);
        }

        $refs = $this->raw->commits($checkout, $branch);
        $this->write($checkout, 'commits', $key, $refs);

        return $refs;
    }

    /**
     * Bypass the cache entirely: fetch, re-list both kinds live, and overwrite
     * the persistent rows. This is the only path that ever calls the raw
     * provider once a cache row already exists.
     */
    public function refresh(RepositoryVersion $checkout): void
    {
        $this->raw->fetch($checkout);

        $branches = $this->raw->branches($checkout);
        $this->write($checkout, 'branches', '', $branches);

        $branchKey = $checkout->repository?->default_branch ?? 'master';
        $commits = $this->raw->commits($checkout, $branchKey);
        $this->write($checkout, 'commits', $branchKey, $commits);
    }

    private function find(RepositoryVersion $checkout, string $kind, string $branch): ?GitRefCache
    {
        return GitRefCache::query()
            ->where('repository_version_id', $checkout->getKey())
            ->where('kind', $kind)
            ->where('branch', $branch)
            ->first();
    }

    /**
     * @param  list<GitRef>  $refs
     */
    private function write(RepositoryVersion $checkout, string $kind, string $branch, array $refs): void
    {
        GitRefCache::query()->updateOrCreate(
            [
                'repository_version_id' => $checkout->getKey(),
                'kind' => $kind,
                'branch' => $branch,
            ],
            [
                'payload' => $this->encode($refs),
                'fetched_at' => now(),
            ],
        );
    }

    /**
     * @param  list<GitRef>  $refs
     * @return list<array<string, mixed>>
     */
    private function encode(array $refs): array
    {
        return array_map(fn (GitRef $ref): array => [
            'value' => $ref->value,
            'subject' => $ref->subject,
            'author' => $ref->author,
            'date' => $ref->date,
            'is_default' => $ref->isDefault,
        ], $refs);
    }

    /**
     * @return list<GitRef>
     */
    private function decode(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $refs = [];

        foreach ($payload as $row) {
            if (! is_array($row) || ! isset($row['value'])) {
                continue;
            }

            $refs[] = new GitRef(
                value: (string) $row['value'],
                subject: isset($row['subject']) ? (string) $row['subject'] : null,
                author: isset($row['author']) ? (string) $row['author'] : null,
                date: isset($row['date']) ? (string) $row['date'] : null,
                isDefault: (bool) ($row['is_default'] ?? false),
            );
        }

        return $refs;
    }
}
