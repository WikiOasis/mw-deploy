<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Models\GitFileCacheEntry;
use App\Models\RepositoryVersion;
use App\Services\Git\Contracts\GitFileBrowser;
use Illuminate\Support\Facades\Storage;

/**
 * Caches tree listings and blob content by *resolved* commit SHA, never by a
 * branch name — a SHA never changes what it points at, so every row here is
 * immutable and correct forever, with no staleness question to ask.
 *
 * Blob content above config('mwdeploy.git.blob_disk_threshold') spills to a file
 * under storage/app/git-file-cache instead of the payload JSON column, so a
 * handful of large files browsed once do not bloat every row fetch.
 */
final class CachedGitFileBrowser implements GitFileBrowser
{
    private const DISK = 'local';

    public function __construct(private readonly GitFileBrowser $raw) {}

    public function resolve(RepositoryVersion $checkout, string $ref): string
    {
        // A full 40-character SHA is already resolved; skip the round-trip.
        if (preg_match('/^[0-9a-f]{40}$/i', $ref) === 1) {
            return strtolower($ref);
        }

        return $this->raw->resolve($checkout, $ref);
    }

    public function tree(RepositoryVersion $checkout, string $sha, string $path): array
    {
        $path = trim($path, '/');
        $entry = $this->find($checkout, $sha, 'tree', $path);

        if ($entry !== null) {
            $entry->touchAccessed();

            return $this->decodeTree($entry->payload ?? []);
        }

        $entries = $this->raw->tree($checkout, $sha, $path);

        GitFileCacheEntry::query()->create([
            'repository_version_id' => $checkout->getKey(),
            'commit_sha' => $sha,
            'kind' => 'tree',
            'path' => $path,
            'payload' => $this->encodeTree($entries),
            'size' => count($entries),
            'last_accessed_at' => now(),
        ]);

        return $entries;
    }

    public function blob(RepositoryVersion $checkout, string $sha, string $path): GitBlob
    {
        $path = trim($path, '/');
        $entry = $this->find($checkout, $sha, 'blob', $path);

        if ($entry !== null) {
            $entry->touchAccessed();

            return new GitBlob(
                content: $entry->disk_path !== null ? $this->readDisk($entry->disk_path) : (string) ($entry->payload['content'] ?? ''),
                size: $entry->size,
                truncated: $entry->truncated,
                binary: $entry->binary,
            );
        }

        $blob = $this->raw->blob($checkout, $sha, $path);
        $this->writeBlob($checkout, $sha, $path, $blob);

        return $blob;
    }

    private function writeBlob(RepositoryVersion $checkout, string $sha, string $path, GitBlob $blob): void
    {
        $threshold = (int) config('mwdeploy.git.blob_disk_threshold', 65536);
        $spillToDisk = ! $blob->binary && strlen($blob->content) > $threshold;

        $diskPath = null;
        $payload = ['content' => $blob->content];

        if ($spillToDisk) {
            $diskPath = 'git-file-cache/'.$checkout->getKey().'/'.$sha.'/'.sha1($path);
            Storage::disk(self::DISK)->put($diskPath, $blob->content);
            $payload = [];
        }

        GitFileCacheEntry::query()->create([
            'repository_version_id' => $checkout->getKey(),
            'commit_sha' => $sha,
            'kind' => 'blob',
            'path' => $path,
            'payload' => $payload,
            'disk_path' => $diskPath,
            'size' => $blob->size,
            'truncated' => $blob->truncated,
            'binary' => $blob->binary,
            'last_accessed_at' => now(),
        ]);
    }

    private function readDisk(string $diskPath): string
    {
        $content = Storage::disk(self::DISK)->get($diskPath);

        return $content ?? '';
    }

    private function find(RepositoryVersion $checkout, string $sha, string $kind, string $path): ?GitFileCacheEntry
    {
        return GitFileCacheEntry::query()
            ->where('repository_version_id', $checkout->getKey())
            ->where('commit_sha', $sha)
            ->where('kind', $kind)
            ->where('path', $path)
            ->first();
    }

    /**
     * @param  list<GitTreeEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function encodeTree(array $entries): array
    {
        return array_map(fn (GitTreeEntry $entry): array => [
            'name' => $entry->name,
            'type' => $entry->type,
            'mode' => $entry->mode,
            'size' => $entry->size,
        ], $entries);
    }

    /**
     * @return list<GitTreeEntry>
     */
    private function decodeTree(array $payload): array
    {
        $entries = [];

        foreach ($payload as $row) {
            if (! is_array($row) || ! isset($row['name'])) {
                continue;
            }

            $entries[] = new GitTreeEntry(
                name: (string) $row['name'],
                type: (string) ($row['type'] ?? 'blob'),
                mode: (string) ($row['mode'] ?? ''),
                size: isset($row['size']) && $row['size'] !== null ? (int) $row['size'] : null,
            );
        }

        return $entries;
    }
}
