<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GitFileCacheEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Reclaims file-browser cache rows nobody has looked at recently.
 *
 * Runs hourly (routes/console.php), independent of the 12h ref-rebuild job:
 * file browsing is accessed at a different cadence than ref listing, and unlike
 * refs there is no fleet-wide reason to keep every commit ever browsed around
 * indefinitely.
 */
final class PruneGitFileCacheCommand extends Command
{
    protected $signature = 'mwdeploy:prune-git-file-cache {--hours=24 : Reclaim rows untouched for longer than this}';

    protected $description = 'Delete file-browser cache rows (and their disk-spilled blobs) past their TTL';

    public function handle(): int
    {
        $cutoff = now()->subHours(max(1, (int) $this->option('hours')));

        $stale = GitFileCacheEntry::query()->where('last_accessed_at', '<', $cutoff)->get();

        foreach ($stale as $entry) {
            if ($entry->disk_path !== null) {
                Storage::disk('local')->delete($entry->disk_path);
            }
        }

        $count = $stale->count();

        GitFileCacheEntry::query()->whereIn('id', $stale->pluck('id'))->delete();

        $this->components->info("Pruned {$count} stale cache entr".($count === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
