<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Models\Repository;
use App\Services\Git\Contracts\GitRefProvider;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Reads branches and commits straight out of the staging clone on this host.
 *
 * Only valid when the Salt master and the staging tree are the same machine; see
 * SaltGitRefProvider for the general case.
 */
final class LocalGitRefProvider implements GitRefProvider
{
    private const SEPARATOR = "\x1f";

    public function isAvailable(): bool
    {
        return is_dir((string) config('mwdeploy.paths.staging'));
    }

    public function branches(Repository $repository): array
    {
        $lines = $this->cached($repository, 'branches', fn (): array => $this->git($repository, [
            'for-each-ref',
            '--sort=-committerdate',
            // lstrip=3 drops "refs/remotes/origin/", which also turns the
            // origin/HEAD symref into a bare "HEAD" we can filter out.
            '--format=%(refname:lstrip=3)'.self::SEPARATOR.'%(subject)'.self::SEPARATOR.'%(authorname)'.self::SEPARATOR.'%(committerdate:iso8601)',
            'refs/remotes/origin',
        ]));

        $branches = [];

        foreach ($lines as $line) {
            [$name, $subject, $author, $date] = $this->split($line);

            if ($name === '' || $name === 'HEAD') {
                continue;
            }

            $branches[] = new GitRef(
                value: $name,
                subject: $subject,
                author: $author,
                date: $date,
                isDefault: $name === $repository->default_branch,
            );
        }

        return $this->defaultFirst($branches, $repository);
    }

    public function commits(Repository $repository, ?string $branch = null): array
    {
        $branch ??= $repository->default_branch;
        $limit = max(1, (int) config('mwdeploy.git.commit_limit', 30));

        $lines = $this->cached($repository, 'commits:'.$branch, fn (): array => $this->git($repository, [
            'log',
            '--max-count='.$limit,
            '--format=%H'.self::SEPARATOR.'%s'.self::SEPARATOR.'%an'.self::SEPARATOR.'%aI',
            'origin/'.$branch,
        ]));

        $commits = [];

        foreach ($lines as $line) {
            [$sha, $subject, $author, $date] = $this->split($line);

            if ($sha === '') {
                continue;
            }

            $commits[] = new GitRef($sha, $subject, $author, $date);
        }

        return $commits;
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    private function git(Repository $repository, array $arguments): array
    {
        $path = $repository->stagingPath();

        if (! is_dir($path.'/.git') && ! is_dir($path)) {
            return [];
        }

        $process = new Process(
            array_merge([(string) config('mwdeploy.git.binary'), '-C', $path], $arguments),
        );
        $process->setTimeout((float) config('mwdeploy.git.process_timeout', 60));

        try {
            $process->run();
        } catch (ExceptionInterface) {
            return [];
        }

        if (! $process->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $process->getOutput()) ?: [],
            fn (string $line) => trim($line) !== '',
        ));
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function split(string $line): array
    {
        $parts = explode(self::SEPARATOR, $line);

        return [
            trim($parts[0] ?? ''),
            trim($parts[1] ?? ''),
            trim($parts[2] ?? ''),
            trim($parts[3] ?? ''),
        ];
    }

    /**
     * @param  list<GitRef>  $branches
     * @return list<GitRef>
     */
    private function defaultFirst(array $branches, Repository $repository): array
    {
        usort($branches, function (GitRef $a, GitRef $b) use ($repository): int {
            return ($b->value === $repository->default_branch ? 1 : 0)
                <=> ($a->value === $repository->default_branch ? 1 : 0);
        });

        return $branches;
    }

    /**
     * @param  callable(): list<string>  $resolver
     * @return list<string>
     */
    private function cached(Repository $repository, string $key, callable $resolver): array
    {
        return Cache::remember(
            'mwdeploy:refs:local:'.$repository->getKey().':'.$key,
            now()->addSeconds(60),
            $resolver,
        );
    }
}
