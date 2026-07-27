<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Import\ApplyImport;
use App\Models\User;
use App\Services\Discovery\ImportPlan;
use App\Services\Discovery\ImportPlanEntry;
use App\Services\Discovery\ImportPlanner;
use App\Services\Discovery\ScanFailed;
use App\Services\Discovery\TreeScanner;
use Illuminate\Console\Command;

/**
 * Fill the registry in from the MediaWiki tree that is already on disk.
 *
 * The same scan, planner and apply step the import screen uses, on the command
 * line, so a fresh install can be populated during setup — before anyone has
 * signed in — rather than by clicking through a list of two hundred extensions.
 *
 *   php artisan mwdeploy:import-tree                 # show the plan, change nothing
 *   php artisan mwdeploy:import-tree --apply         # adopt everything additive
 *   php artisan mwdeploy:import-tree --apply --repin # also match pins to the tree
 */
final class ImportTreeCommand extends Command
{
    protected $signature = 'mwdeploy:import-tree
                            {--apply : Write the plan to the registry; without this nothing changes}
                            {--repin : Also update pins and remotes that disagree with the tree}
                            {--prune : Also mark checkouts the tree does not have as undeployed}
                            {--only-version=* : Restrict to these core versions}
                            {--as= : Email of the account to attribute the import to}
                            {--fresh : Ignore any cached scan}';

    protected $description = 'Scan the MediaWiki tree and register what is already deployed';

    public function handle(TreeScanner $scanner, ImportPlanner $planner, ApplyImport $apply): int
    {
        try {
            $scan = $scanner->scan(
                versions: array_values(array_filter((array) $this->option('only-version'))),
                fresh: (bool) $this->option('fresh'),
            );
        } catch (ScanFailed $failure) {
            $this->components->error($failure->getMessage());

            return self::FAILURE;
        }

        $plan = $planner->plan($scan);

        $this->components->info(sprintf(
            'Scanned %s: %d core version(s), %d checkout(s) on disk.',
            $scan->root,
            count($scan->versions),
            $scan->checkouts->count(),
        ));

        $this->renderPlan($plan);

        foreach ($scan->warnings as $warning) {
            $this->components->warn($warning);
        }

        if ($plan->isEmpty()) {
            $this->components->info('The registry already describes this tree. Nothing to import.');

            return self::SUCCESS;
        }

        // Even a dry run records what the scan saw, so the drift columns on the
        // repository screens are populated without anyone having to import.
        $apply->recordObservations($plan);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->components->warn('Dry run. Re-run with --apply to write these changes.');

            return self::SUCCESS;
        }

        $actor = $this->resolveActor();

        if ($actor === null) {
            return self::FAILURE;
        }

        $keys = $this->selectedKeys($plan);

        if ($keys === []) {
            $this->components->info('Nothing selected to apply.');

            return self::SUCCESS;
        }

        $outcome = $apply($plan, $actor, $keys);

        foreach ($outcome['summary'] as $line) {
            $this->line('  <fg=green>✔</> '.$line);
        }

        $this->newLine();
        $this->components->info(sprintf(
            'Imported %d change(s): %d repository/repositories, %d checkout(s), %d version(s).',
            $outcome['applied'],
            $outcome['repositories'],
            $outcome['checkouts'],
            $outcome['versions'],
        ));

        return self::SUCCESS;
    }

    /**
     * Which plan entries this invocation should write.
     *
     * Additive by default. A repin rewrites a decision someone made deliberately,
     * and marking things undeployed rewrites the portal's picture of the fleet, so
     * both stay behind their own flags — an unattended setup run should never do
     * either.
     *
     * @return list<string>
     */
    private function selectedKeys(ImportPlan $plan): array
    {
        return $plan->actionable()
            ->filter(function (ImportPlanEntry $entry): bool {
                if ($entry->action->isAdditive()) {
                    return true;
                }

                return match ($entry->action->value) {
                    'repin', 'update_url' => (bool) $this->option('repin'),
                    'mark_undeployed' => (bool) $this->option('prune'),
                    default => false,
                };
            })
            ->pluck('key')
            ->all();
    }

    private function renderPlan(ImportPlan $plan): void
    {
        $rows = $plan->entries
            ->filter(static fn (ImportPlanEntry $entry): bool => $entry->action->isActionable())
            ->map(static fn (ImportPlanEntry $entry): array => [
                $entry->action->label(),
                $entry->type->value,
                $entry->name,
                $entry->version ?? '—',
                $entry->scanned?->ref ?? '—',
                $entry->path,
            ])
            ->all();

        if ($rows !== []) {
            $this->newLine();
            $this->table(['Action', 'Type', 'Name', 'Version', 'Ref on disk', 'Path'], $rows);
        }

        $counts = $plan->counts();
        $inSync = $counts['in_sync'] ?? 0;
        $blocked = $counts['unimportable'] ?? 0;

        if ($inSync > 0 || $blocked > 0) {
            $this->components->twoColumnDetail('Already in sync', (string) $inSync);
            $this->components->twoColumnDetail('Cannot be imported', (string) $blocked);
        }
    }

    /**
     * Who to attribute the import to.
     *
     * Registry rows record a creator, and "whoever ran the installer" is a real
     * answer worth keeping. Falls back to the only account there is on a fresh
     * install, and refuses to guess when there are several.
     */
    private function resolveActor(): ?User
    {
        $email = trim((string) $this->option('as'));

        if ($email !== '') {
            $user = User::query()->where('email', strtolower($email))->first();

            if ($user === null) {
                $this->components->error('No account with the email '.$email.'.');
            }

            return $user;
        }

        $users = User::query()->orderBy('id')->limit(2)->get();

        if ($users->count() === 1) {
            return $users->first();
        }

        if ($users->isEmpty()) {
            $this->components->error(
                'No accounts exist yet. Create one with mwdeploy:create-user, then re-run this with --as=<email>.'
            );

            return null;
        }

        $this->components->error('Several accounts exist; say which one to attribute the import to with --as=<email>.');

        return null;
    }
}
