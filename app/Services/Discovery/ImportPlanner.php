<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Enums\RepositoryType;
use App\Models\MediaWikiVersion;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use Illuminate\Support\Collection;

/**
 * Diffs a scanned MediaWiki tree against the registry.
 *
 * Pure: it reads the registry and the scan and produces a list of proposed
 * changes. Nothing is written here, which is what makes the review screen honest —
 * the plan an operator ticks boxes on is computed by the same code that applies
 * them, from the same scan.
 */
final class ImportPlanner
{
    public function plan(TreeScan $scan): ImportPlan
    {
        $versions = MediaWikiVersion::query()->get()->keyBy('version');

        $repositories = Repository::query()->get();
        $byTypeAndName = $repositories->keyBy(
            static fn (Repository $repository): string => $repository->type->value.'/'.$repository->name
        );
        $core = $repositories->firstWhere('type', RepositoryType::Core);

        $checkouts = RepositoryVersion::query()->with(['repository', 'mediawikiVersion'])->get();
        $byPath = $checkouts->keyBy(static fn (RepositoryVersion $checkout): string => trim($checkout->path, '/'));
        $byRepositoryAndVersion = $checkouts->keyBy(
            static fn (RepositoryVersion $checkout): string => $checkout->repository_id.'@'.($checkout->mediawiki_version_id ?? 0)
        );

        $entries = collect();
        $seenPaths = [];

        // Core versions that exist as versions/<ver> on disk but not in the
        // registry. Listed first because every checkout inside one depends on it.
        foreach ($scan->versions as $version) {
            if ($versions->has($version)) {
                continue;
            }

            $coreVersion = $scan->coreVersionFor($version);
            $wikis = $scan->wikisOn($version);

            $entries->push(new ImportPlanEntry(
                key: 'version:'.$version,
                action: ImportAction::CreateVersion,
                type: RepositoryType::Core,
                name: $version,
                version: $version,
                path: 'versions/'.$version,
                summary: 'Register core version '.$version
                    .($coreVersion === null ? '' : ' (MW_VERSION '.$coreVersion.')'),
                current: ['registered' => 'no'],
                proposed: ['registered' => 'yes', 'core_version' => $coreVersion],
                scanned: $scan->ofType(RepositoryType::Core)->firstWhere('version', $version),
                note: $wikis === []
                    ? null
                    : count($wikis).' wiki(s) are pointed at this version: '.implode(', ', array_slice($wikis, 0, 8))
                        .(count($wikis) > 8 ? ' …' : ''),
            ));
        }

        foreach ($scan->checkouts as $scanned) {
            $seenPaths[$scanned->path] = true;

            $blocker = $scanned->blocker();

            if ($blocker !== null && ! (bool) config('mwdeploy.discovery.import_non_git', false)) {
                $entries->push(new ImportPlanEntry(
                    key: $scanned->path,
                    action: ImportAction::Unimportable,
                    type: $scanned->type,
                    name: $scanned->name,
                    version: $scanned->version,
                    path: $scanned->path,
                    summary: $scanned->name.' is '.$blocker,
                    scanned: $scanned,
                    note: 'Left alone. Either clone it from a remote, or keep managing it by hand.',
                ));

                continue;
            }

            // Core is matched by type rather than name: there is one logical
            // MediaWiki core repository however many version trees it has, and
            // farms spell its registry entry differently ("mediawiki", "core").
            $repository = $scanned->type === RepositoryType::Core
                ? $core
                : $byTypeAndName->get($scanned->type->value.'/'.$scanned->name);

            $version = $scanned->version === null ? null : $versions->get($scanned->version);

            $checkout = $repository === null
                ? null
                : $byRepositoryAndVersion->get($repository->getKey().'@'.($version?->getKey() ?? 0));

            // Another repository already owns this path. repository_versions.path is
            // unique, so importing would fail at the database — and a path collision
            // means one of the two rows is wrong about what is on disk, which a human
            // needs to look at rather than have resolved for them.
            $pathOwner = $byPath->get($scanned->path);

            if ($pathOwner !== null && ($checkout === null || $pathOwner->getKey() !== $checkout->getKey())) {
                $entries->push(new ImportPlanEntry(
                    key: $scanned->path,
                    action: ImportAction::Unimportable,
                    type: $scanned->type,
                    name: $scanned->name,
                    version: $scanned->version,
                    path: $scanned->path,
                    summary: $scanned->path.' is already registered to '.($pathOwner->repository?->name ?? 'another repository'),
                    scanned: $scanned,
                    repositoryId: $pathOwner->repository_id,
                    checkoutId: $pathOwner->getKey(),
                    note: 'Two registry rows cannot share a path. Fix the existing row first.',
                ));

                continue;
            }

            $entries->push(match (true) {
                $repository === null => $this->createRepositoryEntry($scanned),
                $checkout === null => $this->createCheckoutEntry($scanned, $repository),
                ! $checkout->isPresent() => $this->adoptEntry($scanned, $repository, $checkout),
                default => $this->pinEntry($scanned, $repository, $checkout),
            });
        }

        // Registry rows that claim to be on disk but were not in the scan. Only
        // meaningful for a full scan: a version-restricted one legitimately does
        // not mention anything outside those versions.
        if ($scan->checkouts->isNotEmpty()) {
            foreach ($checkouts as $checkout) {
                $path = trim($checkout->path, '/');

                if (! $checkout->isPresent() || isset($seenPaths[$path])) {
                    continue;
                }

                $entries->push(new ImportPlanEntry(
                    key: 'missing:'.$path,
                    action: ImportAction::MarkUndeployed,
                    type: $checkout->repository?->type ?? RepositoryType::Extension,
                    name: $checkout->repository?->name ?? $path,
                    version: $checkout->mediawikiVersion?->version,
                    path: $path,
                    summary: ($checkout->repository?->name ?? $path).' is registered as deployed but is not in '.$scan->root,
                    current: ['status' => 'present', 'ref' => $checkout->resolvedRefValue()],
                    proposed: ['status' => 'undeployed'],
                    repositoryId: $checkout->repository_id,
                    checkoutId: $checkout->getKey(),
                    note: 'Registry only. Nothing is removed from any server — the directory is already gone from this tree.',
                ));
            }
        }

        // A repository whose registered remote differs from the one its checkouts
        // actually use. One row per repository, not per checkout: the URL lives on
        // the repository, and N identical rows would be noise.
        $entries = $entries->merge($this->urlDriftEntries($scan, $repositories, $core));

        return new ImportPlan($scan, $this->sort($entries));
    }

    private function createRepositoryEntry(ScannedCheckout $scanned): ImportPlanEntry
    {
        $name = $scanned->type === RepositoryType::Core ? 'mediawiki' : $scanned->name;

        return new ImportPlanEntry(
            key: $scanned->path,
            action: ImportAction::CreateRepository,
            type: $scanned->type,
            name: $name,
            version: $scanned->version,
            path: $scanned->path,
            summary: 'Register '.$name.' from '.$scanned->gitUrl.', pinned to '.$scanned->ref,
            current: ['registered' => 'no'],
            proposed: [
                'git_url' => $scanned->gitUrl,
                'default_branch' => $scanned->inferredDefaultBranch(),
                'ref' => $scanned->ref,
                'status' => 'present',
            ],
            scanned: $scanned,
            note: $scanned->hasSubmodules
                ? 'This checkout has submodules; the shim clones with --recurse-submodules when restoring it.'
                : null,
        );
    }

    private function createCheckoutEntry(ScannedCheckout $scanned, Repository $repository): ImportPlanEntry
    {
        return new ImportPlanEntry(
            key: $scanned->path,
            action: ImportAction::CreateCheckout,
            type: $scanned->type,
            name: $repository->name,
            version: $scanned->version,
            path: $scanned->path,
            summary: $repository->name.' is registered but has no checkout row for '
                .($scanned->version ?? 'the top level').'; it is on disk at '.$scanned->ref,
            current: ['registered' => 'repository only'],
            proposed: ['ref' => $scanned->ref, 'status' => 'present'],
            scanned: $scanned,
            repositoryId: $repository->getKey(),
        );
    }

    private function adoptEntry(
        ScannedCheckout $scanned,
        Repository $repository,
        RepositoryVersion $checkout,
    ): ImportPlanEntry {
        return new ImportPlanEntry(
            key: $scanned->path,
            action: ImportAction::AdoptCheckout,
            type: $scanned->type,
            name: $repository->name,
            version: $scanned->version,
            path: $scanned->path,
            summary: $repository->name.' is marked undeployed but is on disk at '.$scanned->ref,
            current: ['status' => 'undeployed', 'ref' => $checkout->resolvedRefValue()],
            proposed: ['status' => 'present', 'ref' => $scanned->ref],
            scanned: $scanned,
            repositoryId: $repository->getKey(),
            checkoutId: $checkout->getKey(),
        );
    }

    /**
     * A present checkout: either the pin matches the tree, or it does not.
     */
    private function pinEntry(
        ScannedCheckout $scanned,
        Repository $repository,
        RepositoryVersion $checkout,
    ): ImportPlanEntry {
        $pinned = $checkout->resolvedRefValue();
        $drifted = $pinned !== null && $pinned !== $scanned->ref
            && ! ($scanned->commit !== null && str_starts_with($scanned->commit, $pinned));

        if (! $drifted) {
            return new ImportPlanEntry(
                key: $scanned->path,
                action: ImportAction::InSync,
                type: $scanned->type,
                name: $repository->name,
                version: $scanned->version,
                path: $scanned->path,
                summary: $repository->name.' matches the registry at '.($pinned ?? $scanned->ref),
                current: ['ref' => $pinned ?? $scanned->ref],
                scanned: $scanned,
                repositoryId: $repository->getKey(),
                checkoutId: $checkout->getKey(),
            );
        }

        return new ImportPlanEntry(
            key: $scanned->path,
            action: ImportAction::Repin,
            type: $scanned->type,
            name: $repository->name,
            version: $scanned->version,
            path: $scanned->path,
            summary: $repository->name.' pins '.$pinned.' but the tree is on '.$scanned->ref,
            current: ['ref' => $pinned, 'ref_mode' => $checkout->ref_mode->value],
            proposed: ['ref' => $scanned->ref, 'ref_mode' => 'pinned'],
            scanned: $scanned,
            repositoryId: $repository->getKey(),
            checkoutId: $checkout->getKey(),
            note: 'Not selected by default: the pin may be deliberate and the tree the thing that is behind. '
                .'Deploying this checkout would move the tree to '.$pinned.'.',
        );
    }

    /**
     * @param  Collection<int, Repository>  $repositories
     * @return Collection<int, ImportPlanEntry>
     */
    private function urlDriftEntries(TreeScan $scan, Collection $repositories, ?Repository $core): Collection
    {
        $entries = collect();

        foreach ($repositories as $repository) {
            $scanned = $scan->checkouts->first(
                fn (ScannedCheckout $checkout): bool => $checkout->gitUrl !== null
                    && ($repository->type === RepositoryType::Core
                        ? $checkout->type === RepositoryType::Core && $core?->is($repository)
                        : $checkout->type === $repository->type && $checkout->name === $repository->name)
            );

            if ($scanned === null || $this->sameRemote($repository->git_url, (string) $scanned->gitUrl)) {
                continue;
            }

            $entries->push(new ImportPlanEntry(
                key: 'url:'.$repository->getKey(),
                action: ImportAction::UpdateUrl,
                type: $repository->type,
                name: $repository->name,
                version: null,
                path: $scanned->path,
                summary: $repository->name.' is registered as '.$repository->git_url
                    .' but the checkout on disk came from '.$scanned->gitUrl,
                current: ['git_url' => $repository->git_url],
                proposed: ['git_url' => $scanned->gitUrl],
                scanned: $scanned,
                repositoryId: $repository->getKey(),
                note: 'Not selected by default: a migration to a new git host looks exactly like this, '
                    .'and so does a checkout someone cloned from the wrong place.',
            ));
        }

        return $entries;
    }

    /**
     * Whether two remotes are the same place written two ways.
     *
     * Trailing ".git", a trailing slash and scp-style vs ssh:// spellings all
     * describe one remote; flagging those as drift would bury the real ones.
     */
    private function sameRemote(string $registered, string $observed): bool
    {
        return $this->normaliseRemote($registered) === $this->normaliseRemote($observed);
    }

    private function normaliseRemote(string $url): string
    {
        $url = trim($url);
        $url = preg_replace('#^(https?|ssh|git)://#i', '', $url) ?? $url;
        // git@host:path → host/path
        $url = preg_replace('#^[^/@]+@([^:/]+):#', '$1/', $url) ?? $url;
        $url = rtrim($url, '/');
        $url = preg_replace('/\.git$/i', '', $url) ?? $url;

        return strtolower($url);
    }

    /**
     * Actionable rows first, then by type, then version, then name — so the screen
     * opens on the work rather than on a wall of "in sync".
     *
     * @param  Collection<int, ImportPlanEntry>  $entries
     * @return Collection<int, ImportPlanEntry>
     */
    private function sort(Collection $entries): Collection
    {
        $typeOrder = [
            RepositoryType::Core->value => 0,
            RepositoryType::Extension->value => 1,
            RepositoryType::Skin->value => 2,
            RepositoryType::Config->value => 3,
        ];

        /*
         * One composite key rather than a list of them: Collection::sortBy() reads a
         * *callable* in a multi-key array as a comparator taking ($a, $b), not as a
         * key extractor — so a list of single-argument closures silently sorts by the
         * first one and leaves the rest to chance.
         */
        return $entries->sortBy(fn (ImportPlanEntry $entry): string => implode('|', [
            $entry->action->isActionable() ? '0' : '1',
            $entry->action === ImportAction::CreateVersion ? '0' : '1',
            $typeOrder[$entry->type->value] ?? 9,
            $this->versionSortKey($entry->version),
            strtolower($entry->name),
        ]))->values();
    }

    /**
     * Order 1.9 before 1.45 — a plain string sort gets that backwards, and a
     * version list is exactly where it shows.
     */
    private function versionSortKey(?string $version): string
    {
        if ($version === null) {
            return 'zzzz';
        }

        return implode('.', array_map(
            static fn (string $part): string => str_pad($part, 6, '0', STR_PAD_LEFT),
            explode('.', $version),
        ));
    }
}
