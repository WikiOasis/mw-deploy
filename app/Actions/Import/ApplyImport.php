<?php

declare(strict_types=1);

namespace App\Actions\Import;

use App\Enums\PresenceStatus;
use App\Enums\RefMode;
use App\Enums\RefType;
use App\Enums\RepositoryType;
use App\Models\MediaWikiVersion;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use App\Models\User;
use App\Services\Discovery\ImportAction;
use App\Services\Discovery\ImportPlan;
use App\Services\Discovery\ImportPlanEntry;
use App\Services\Discovery\ScannedCheckout;
use App\Support\PathResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Write the selected lines of an import plan into the registry.
 *
 * This is the one place in the application that creates a checkout as *already
 * present* without a deployment behind it, and that is the whole point: the code
 * is on disk, put there by whatever managed the farm before this portal existed.
 * Cloning it again would be wrong, and modelling it as a deployment would put a
 * fictional entry in history.
 *
 * The corollary is that this action never touches the tree — no clone, no
 * checkout, no rsync, no removal. The worst an import can do is describe the farm
 * incorrectly, which the next scan will show.
 */
final class ApplyImport
{
    public function __construct(private readonly PathResolver $paths) {}

    /**
     * @param  list<string>  $keys  plan entry keys to apply; empty applies the
     *                              recommended set
     * @return array{applied: int, skipped: int, summary: list<string>, repositories: int, checkouts: int, versions: int}
     */
    public function __invoke(ImportPlan $plan, User $actor, array $keys = []): array
    {
        $entries = $keys === [] ? $plan->recommended() : $plan->only($keys);

        // Versions first: a checkout inside versions/1.46 needs its
        // mediawiki_versions row to exist before it can be pointed at it.
        $entries = $entries->sortBy(
            static fn (ImportPlanEntry $entry): int => $entry->action === ImportAction::CreateVersion ? 0 : 1
        )->values();

        $summary = [];
        $counts = ['repositories' => 0, 'checkouts' => 0, 'versions' => 0];
        $skipped = 0;

        DB::transaction(function () use ($entries, $actor, &$summary, &$counts, &$skipped): void {
            $versions = MediaWikiVersion::query()->get()->keyBy('version');

            foreach ($entries as $entry) {
                $line = match ($entry->action) {
                    ImportAction::CreateVersion => $this->createVersion($entry, $actor, $versions, $counts),
                    ImportAction::CreateRepository => $this->createRepository($entry, $actor, $versions, $counts),
                    ImportAction::CreateCheckout => $this->createCheckout($entry, $versions, $counts),
                    ImportAction::AdoptCheckout => $this->adopt($entry, $counts),
                    ImportAction::Repin => $this->repin($entry),
                    ImportAction::UpdateUrl => $this->updateUrl($entry),
                    ImportAction::MarkUndeployed => $this->markUndeployed($entry),
                    ImportAction::InSync, ImportAction::Unimportable => null,
                };

                if ($line === null) {
                    $skipped++;

                    continue;
                }

                $summary[] = $line;
            }
        });

        return [
            'applied' => count($summary),
            'skipped' => $skipped,
            'summary' => $summary,
            ...$counts,
        ];
    }

    /**
     * @param  Collection<string, MediaWikiVersion>  $versions
     * @param  array<string, int>  $counts
     */
    private function createVersion(
        ImportPlanEntry $entry,
        User $actor,
        Collection $versions,
        array &$counts,
    ): ?string {
        $version = $entry->version;

        if ($version === null || ! $this->paths->isValidVersion($version)) {
            return null;
        }

        if ($versions->has($version)) {
            return null;
        }

        $model = MediaWikiVersion::query()->create([
            'version' => $version,
            // Present, not "active pending a deployment": the tree is on disk.
            'status' => PresenceStatus::Present->value,
            'created_by' => $actor->getKey(),
            'discovered_at' => now(),
            'core_version' => $entry->proposed['core_version'] ?? null,
        ]);

        $versions->put($version, $model);
        $counts['versions']++;

        return 'Registered core version '.$version.'.';
    }

    /**
     * @param  Collection<string, MediaWikiVersion>  $versions
     * @param  array<string, int>  $counts
     */
    private function createRepository(
        ImportPlanEntry $entry,
        User $actor,
        Collection $versions,
        array &$counts,
    ): ?string {
        $scanned = $entry->scanned;

        if ($scanned === null || $scanned->gitUrl === null) {
            return null;
        }

        // firstOrCreate, because one repository legitimately appears in several
        // plan entries — Echo under 1.45 and under 1.46 are two checkouts of one
        // repository, and whichever is applied first creates it.
        $repository = Repository::query()->firstOrNew([
            'type' => $entry->type->value,
            'name' => $entry->name,
        ]);

        $created = ! $repository->exists;

        if ($created) {
            $repository->fill([
                'git_url' => $scanned->gitUrl,
                'default_branch' => $scanned->inferredDefaultBranch(),
                'active' => true,
                'created_by' => $actor->getKey(),
                'discovered_at' => now(),
                'manifest' => $scanned->manifest === [] ? null : $scanned->manifest,
            ])->save();

            $counts['repositories']++;
        }

        $checkout = $this->upsertCheckout($repository, $scanned, $versions);

        if ($checkout !== null) {
            $counts['checkouts']++;
        }

        return $created
            ? 'Registered '.$repository->name.' ('.$entry->type->value.') at '.$scanned->path.', pinned to '.$scanned->ref.'.'
            : 'Added '.$repository->name.' checkout at '.$scanned->path.', pinned to '.$scanned->ref.'.';
    }

    /**
     * @param  Collection<string, MediaWikiVersion>  $versions
     * @param  array<string, int>  $counts
     */
    private function createCheckout(ImportPlanEntry $entry, Collection $versions, array &$counts): ?string
    {
        $scanned = $entry->scanned;
        $repository = $entry->repositoryId === null ? null : Repository::query()->find($entry->repositoryId);

        if ($scanned === null || $repository === null) {
            return null;
        }

        if ($this->upsertCheckout($repository, $scanned, $versions) === null) {
            return null;
        }

        $counts['checkouts']++;

        return 'Added '.$repository->name.' checkout at '.$scanned->path.', pinned to '.$scanned->ref.'.';
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function adopt(ImportPlanEntry $entry, array &$counts): ?string
    {
        $checkout = $entry->checkoutId === null ? null : RepositoryVersion::query()->find($entry->checkoutId);
        $scanned = $entry->scanned;

        if ($checkout === null || $scanned === null) {
            return null;
        }

        $checkout->forceFill([
            'status' => PresenceStatus::Present->value,
            'undeployed_at' => null,
            'registered_at' => $checkout->registered_at ?? now(),
            'discovered_at' => $checkout->discovered_at ?? now(),
            ...$this->observation($scanned),
            // Adopting means trusting the tree: pin to what is actually checked
            // out, or the first deployment of this checkout would move it.
            'ref_mode' => RefMode::Pinned->value,
            'tracked_ref_type' => $scanned->refType?->value ?? RefType::detect((string) $scanned->ref)->value,
            'tracked_ref_value' => $scanned->ref,
        ])->save();

        $counts['checkouts']++;

        return 'Adopted '.$entry->name.' at '.$scanned->path.' as deployed on '.$scanned->ref.'.';
    }

    private function repin(ImportPlanEntry $entry): ?string
    {
        $checkout = $entry->checkoutId === null ? null : RepositoryVersion::query()->find($entry->checkoutId);
        $scanned = $entry->scanned;

        if ($checkout === null || $scanned === null || $scanned->ref === null) {
            return null;
        }

        $previous = $checkout->resolvedRefValue();

        $checkout->forceFill([
            'ref_mode' => RefMode::Pinned->value,
            'tracked_ref_type' => $scanned->refType?->value ?? RefType::detect($scanned->ref)->value,
            'tracked_ref_value' => $scanned->ref,
            ...$this->observation($scanned),
        ])->save();

        return 'Repinned '.$entry->name.' ('.($entry->version ?? 'unversioned').') from '
            .($previous ?? 'no pin').' to '.$scanned->ref.'.';
    }

    private function updateUrl(ImportPlanEntry $entry): ?string
    {
        $repository = $entry->repositoryId === null ? null : Repository::query()->find($entry->repositoryId);
        $url = $entry->proposed['git_url'] ?? null;

        if ($repository === null || $url === null) {
            return null;
        }

        $previous = $repository->git_url;
        $repository->update(['git_url' => $url]);

        return 'Repointed '.$repository->name.' from '.$previous.' to '.$url.'.';
    }

    private function markUndeployed(ImportPlanEntry $entry): ?string
    {
        $checkout = $entry->checkoutId === null ? null : RepositoryVersion::query()->find($entry->checkoutId);

        if ($checkout === null) {
            return null;
        }

        $checkout->markUndeployed();
        $checkout->forceFill(['observed_at' => now(), 'observed_ref_value' => null, 'observed_commit' => null])->save();

        return 'Marked '.$entry->name.' at '.$entry->path.' as undeployed; it is not in the tree.';
    }

    /**
     * Create or refresh the checkout row for a scanned directory.
     *
     * The path comes from the scan rather than from PathResolver: this is a record
     * of where the checkout *is*, and a farm may not lay its tree out exactly the
     * way this portal would have. PathResolver still decides paths for anything the
     * portal creates itself.
     *
     * @param  Collection<string, MediaWikiVersion>  $versions
     */
    private function upsertCheckout(
        Repository $repository,
        ScannedCheckout $scanned,
        Collection $versions,
    ): ?RepositoryVersion {
        $version = null;

        if ($scanned->type->isVersioned() && $scanned->version !== null) {
            $version = $versions->get($scanned->version);

            // The version row is not there — either it was not selected on the
            // import screen or it failed validation. Registering the checkout
            // without it would silently make it an unversioned top-level row
            // pointing into a version tree, so it is skipped instead.
            if ($version === null) {
                return null;
            }
        }

        $checkout = RepositoryVersion::query()->firstOrNew([
            'repository_id' => $repository->getKey(),
            'mediawiki_version_id' => $version?->getKey(),
        ]);

        $checkout->forceFill([
            'path' => $scanned->path,
            'ref_mode' => RefMode::Pinned->value,
            'tracked_ref_type' => $scanned->refType?->value ?? RefType::detect((string) $scanned->ref)->value,
            'tracked_ref_value' => $scanned->ref,
            'status' => PresenceStatus::Present->value,
            'registered_at' => $checkout->registered_at ?? now(),
            'undeployed_at' => null,
            'discovered_at' => $checkout->discovered_at ?? now(),
            ...$this->observation($scanned),
        ])->save();

        return $checkout;
    }

    /**
     * What the scan saw, recorded alongside the pin rather than folded into it.
     *
     * @return array<string, mixed>
     */
    private function observation(ScannedCheckout $scanned): array
    {
        return [
            'observed_ref_type' => $scanned->refType?->value,
            'observed_ref_value' => $scanned->ref,
            'observed_commit' => $scanned->commit,
            'observed_at' => now(),
        ];
    }

    /**
     * Refresh the observation columns for everything a scan saw, without importing
     * anything. This is what the repository screens read to show drift, so it runs
     * on every scan — including the ones nobody applies.
     */
    public function recordObservations(ImportPlan $plan): int
    {
        $checkouts = RepositoryVersion::query()->get()->keyBy(
            static fn (RepositoryVersion $checkout): string => trim($checkout->path, '/')
        );

        $touched = 0;

        foreach ($plan->scan->checkouts as $scanned) {
            $checkout = $checkouts->get($scanned->path);

            if ($checkout === null || ! $scanned->isImportable()) {
                continue;
            }

            $checkout->forceFill($this->observation($scanned))->save();
            $touched++;
        }

        // Core version trees report MW_VERSION, which is worth keeping even for
        // versions that were created by the portal rather than imported.
        foreach ($plan->scan->ofType(RepositoryType::Core) as $core) {
            if ($core->version === null || $core->coreVersion === null) {
                continue;
            }

            MediaWikiVersion::query()
                ->where('version', $core->version)
                ->update(['core_version' => $core->coreVersion]);
        }

        return $touched;
    }
}
