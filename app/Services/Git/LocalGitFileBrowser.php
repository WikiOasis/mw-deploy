<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Models\RepositoryVersion;
use App\Services\Git\Contracts\GitFileBrowser;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Reads trees and blobs straight out of the staging clone on this host. Only
 * valid when the Salt master and the staging tree are the same machine; see
 * SaltGitFileBrowser for the general case.
 */
final class LocalGitFileBrowser implements GitFileBrowser
{
    public function resolve(RepositoryVersion $checkout, string $ref): string
    {
        $result = $this->git($checkout, ['rev-parse', '--verify', '--quiet', $ref.'^{commit}']);

        if ($result === null) {
            // The ref may only exist on the remote until we fetch.
            $this->git($checkout, ['fetch', 'origin', '--prune', '--tags']);
            $result = $this->git($checkout, ['rev-parse', '--verify', '--quiet', $ref.'^{commit}']);
        }

        if ($result === null) {
            throw new GitBrowseFailed("Could not resolve ref [{$ref}] in {$checkout->displayName()}.");
        }

        return trim($result);
    }

    public function tree(RepositoryVersion $checkout, string $sha, string $path): array
    {
        $path = trim($path, '/');
        $spec = $path === '' ? "{$sha}:" : "{$sha}:{$path}";

        $output = $this->git($checkout, ['ls-tree', '--long', $spec]);

        if ($output === null) {
            throw new GitBrowseFailed("Could not list [{$path}] at {$sha} in {$checkout->displayName()}.");
        }

        $entries = [];

        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            [$meta, $name] = array_pad(explode("\t", $line, 2), 2, '');
            $fields = preg_split('/\s+/', trim($meta));

            if (count($fields) < 4 || $name === '') {
                continue;
            }

            $entries[] = new GitTreeEntry(
                name: $name,
                type: $fields[1],
                mode: $fields[0],
                size: $fields[3] === '-' ? null : (int) $fields[3],
            );
        }

        return $entries;
    }

    public function blob(RepositoryVersion $checkout, string $sha, string $path): GitBlob
    {
        $path = trim($path, '/');
        $maxBytes = (int) config('mwdeploy.git.blob_max_bytes', 2 * 1024 * 1024);

        $raw = $this->gitRaw($checkout, ['show', "{$sha}:{$path}"]);

        if ($raw === null) {
            throw new GitBrowseFailed("Could not read [{$path}] at {$sha} in {$checkout->displayName()}.");
        }

        $size = strlen($raw);
        $binary = str_contains(substr($raw, 0, 8192), "\0");

        if ($binary) {
            return new GitBlob(content: '', size: $size, truncated: false, binary: true);
        }

        $truncated = $size > $maxBytes;
        $content = $truncated ? substr($raw, 0, $maxBytes) : $raw;

        return new GitBlob(content: $content, size: $size, truncated: $truncated, binary: false);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(RepositoryVersion $checkout, array $arguments): ?string
    {
        $raw = $this->gitRaw($checkout, $arguments);

        return $raw === null ? null : trim($raw);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function gitRaw(RepositoryVersion $checkout, array $arguments): ?string
    {
        $path = $checkout->stagingPath();

        if (! is_dir($path.'/.git') && ! is_dir($path)) {
            return null;
        }

        $process = new Process(
            array_merge([(string) config('mwdeploy.git.binary'), '-C', $path], $arguments),
        );
        $process->setTimeout((float) config('mwdeploy.git.process_timeout', 60));

        try {
            $process->run();
        } catch (ExceptionInterface) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        return $process->getOutput();
    }
}
