<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Enums\StepName;
use App\Models\RepositoryVersion;
use App\Services\Git\Contracts\GitFileBrowser;
use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\SaltCall;
use App\Services\Salt\ShimCommand;

/**
 * Asks the staging minion to resolve refs and read trees/blobs, through the same
 * Salt transport as everything else.
 */
final class SaltGitFileBrowser implements GitFileBrowser
{
    public function __construct(private readonly SaltClient $salt) {}

    public function resolve(RepositoryVersion $checkout, string $ref): string
    {
        $result = $this->salt->run(new SaltCall(
            target: (string) config('mwdeploy.targets.staging'),
            command: ShimCommand::make(StepName::GitResolve)
                ->option('path', $checkout->stagingPath())
                ->option('ref', $ref),
            subject: $checkout->displayName().' @ '.$ref,
        ));

        $sha = $result->ok ? (string) $result->payloadValue('sha', '') : '';

        if ($sha === '') {
            throw new GitBrowseFailed("Could not resolve ref [{$ref}] in {$checkout->displayName()}.");
        }

        return $sha;
    }

    public function tree(RepositoryVersion $checkout, string $sha, string $path): array
    {
        $result = $this->salt->run(new SaltCall(
            target: (string) config('mwdeploy.targets.staging'),
            command: ShimCommand::make(StepName::GitLsTree)
                ->option('path', $checkout->stagingPath())
                ->option('ref', $sha)
                ->optionalOption('dir', trim($path, '/')),
            subject: $checkout->displayName().' @ '.$sha.':'.$path,
        ));

        if (! $result->ok) {
            throw new GitBrowseFailed("Could not list [{$path}] at {$sha} in {$checkout->displayName()}.");
        }

        $raw = $result->payloadValue('entries', []);
        $rows = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];

        $entries = [];

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $entries[] = new GitTreeEntry(
                name: $name,
                type: (string) ($row['type'] ?? 'blob'),
                mode: (string) ($row['mode'] ?? ''),
                size: isset($row['size']) && $row['size'] !== null ? (int) $row['size'] : null,
            );
        }

        return $entries;
    }

    public function blob(RepositoryVersion $checkout, string $sha, string $path): GitBlob
    {
        $maxBytes = (int) config('mwdeploy.git.blob_max_bytes', 2 * 1024 * 1024);

        $result = $this->salt->run(new SaltCall(
            target: (string) config('mwdeploy.targets.staging'),
            command: ShimCommand::make(StepName::GitShowBlob)
                ->option('path', $checkout->stagingPath())
                ->option('ref', $sha)
                ->option('file', trim($path, '/'))
                ->option('max-bytes', $maxBytes),
            subject: $checkout->displayName().' @ '.$sha.':'.$path,
        ));

        if (! $result->ok) {
            throw new GitBrowseFailed("Could not read [{$path}] at {$sha} in {$checkout->displayName()}.");
        }

        return new GitBlob(
            content: (string) $result->payloadValue('content', ''),
            size: (int) $result->payloadValue('size', 0),
            truncated: (bool) $result->payloadValue('truncated', false),
            binary: (bool) $result->payloadValue('binary', false),
        );
    }
}
