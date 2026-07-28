<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\RepositoryVersion;
use App\Services\Git\Contracts\GitRefProvider;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fleet-wide "fetch latest" for every present checkout, run on a schedule
 * (routes/console.php) so the ref cache never goes more than 12 hours stale even
 * if nobody happens to click "Fetch latest" in the UI.
 *
 * Per-checkout failures are logged and skipped rather than aborting the run —
 * one unreachable remote should not stop every other checkout's cache from
 * refreshing, mirroring ImportTreeCommand's tolerance for partial failure.
 */
final class RebuildGitCacheCommand extends Command
{
    protected $signature = 'mwdeploy:rebuild-git-cache';

    protected $description = 'Refresh the persistent branch/commit cache for every checkout on disk';

    public function handle(GitRefProvider $refs): int
    {
        $checkouts = RepositoryVersion::query()->present()->get();

        $failures = 0;

        foreach ($checkouts as $checkout) {
            try {
                $refs->refresh($checkout);
            } catch (Throwable $exception) {
                $failures++;
                $this->components->warn("{$checkout->displayName()}: {$exception->getMessage()}");
            }
        }

        $this->components->info(sprintf(
            'Refreshed %d checkout(s), %d failure(s).',
            $checkouts->count() - $failures,
            $failures,
        ));

        return self::SUCCESS;
    }
}
