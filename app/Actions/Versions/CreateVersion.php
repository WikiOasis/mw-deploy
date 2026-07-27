<?php

declare(strict_types=1);

namespace App\Actions\Versions;

use App\Actions\Repositories\RegisterCheckout;
use App\Enums\DeploymentIntent;
use App\Enums\DeploymentStatus;
use App\Enums\PresenceStatus;
use App\Enums\RefMode;
use App\Enums\RefType;
use App\Enums\RepoAction;
use App\Enums\RepositoryType;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\MediaWikiVersion;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use App\Models\User;
use App\Support\DeploymentOptions;
use App\Support\PathResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cut a new MediaWiki core version by reconstructing it from an existing one.
 *
 * "Create 1.46 from 1.45" registers a checkout for core plus every extension and
 * skin that 1.45 currently has, then dispatches an ordinary deployment that
 * scaffolds versions/1.46, clones each repository and checks out the chosen ref.
 *
 * Doing it as a normal deployment rather than a bespoke routine is the whole
 * point: it shows up on the review screen call-by-call, streams to the live
 * dashboard, and is rollbackable — undoing it removes the version, because the
 * snapshots say every checkout was absent beforehand.
 */
final class CreateVersion
{
    public function __construct(
        private readonly RegisterCheckout $registerCheckout,
        private readonly PathResolver $paths,
    ) {}

    /**
     * @param  array<int, array{ref_mode?: string, ref?: string|null}>  $overrides
     *                                                                              keyed by repository id
     * @return array{version: MediaWikiVersion|null, deployment: Deployment|null, error: string|null}
     */
    public function __invoke(
        User $actor,
        string $version,
        ?MediaWikiVersion $source,
        string $coreRef,
        DeploymentOptions $options,
        array $overrides = [],
        bool $dispatch = true,
    ): array {
        if (! $this->paths->isValidVersion($version)) {
            return $this->failure('"'.$version.'" is not a MediaWiki version number like 1.46.');
        }

        if (MediaWikiVersion::query()->where('version', $version)->exists()) {
            return $this->failure('Version '.$version.' already exists.');
        }

        $core = Repository::query()->active()->ofType(RepositoryType::Core)->first();

        if ($core === null) {
            return $this->failure(
                'No MediaWiki core repository is registered, so there is nothing to build a version from.'
            );
        }

        [$created, $deployment] = DB::transaction(function () use (
            $actor, $version, $source, $core, $coreRef, $options, $overrides
        ): array {
            $created = MediaWikiVersion::query()->create([
                'version' => $version,
                // Not present until the deployment has actually built it.
                'status' => PresenceStatus::Undeployed->value,
                'created_from_id' => $source?->getKey(),
                'created_by' => $actor->getKey(),
                'sort_order' => 0,
            ]);

            $deployment = Deployment::query()->create([
                'created_by' => $actor->getKey(),
                'status' => DeploymentStatus::Pending->value,
                'intent' => DeploymentIntent::VersionCreate->value,
                'mediawiki_version_id' => $created->getKey(),
                'options' => $options->toArray(),
            ]);

            // Core first: everything else lives inside its directory.
            $this->addCheckout($deployment, $core, $created, $coreRef, RefMode::Pinned);

            foreach ($this->sourceCheckouts($source) as $checkout) {
                $repository = $checkout->repository;

                if ($repository === null) {
                    continue;
                }

                $override = $overrides[$repository->getKey()] ?? [];

                $refMode = RefMode::tryFrom((string) ($override['ref_mode'] ?? '')) ?? $checkout->ref_mode;
                $ref = $override['ref'] ?? null;
                $ref = is_string($ref) && trim($ref) !== '' ? trim($ref) : null;

                // With no override, carry the source version's own pin forward.
                // That is usually wrong for a release branch and right for
                // everything else, which is exactly why the wizard shows the
                // resolved ref per repository before confirming.
                $ref ??= $checkout->resolvedRefValue() ?? $repository->default_branch;

                $this->addCheckout($deployment, $repository, $created, $ref, $refMode);
            }

            return [$created, $deployment];
        });

        if ($dispatch) {
            RunDeployment::dispatch($deployment->getKey());
        }

        return ['version' => $created, 'deployment' => $deployment, 'error' => null];
    }

    /**
     * Extensions and skins present in the source version. Core is handled
     * separately (it *is* the version) and config lives outside the version tree.
     *
     * @return Collection<int, RepositoryVersion>
     */
    private function sourceCheckouts(?MediaWikiVersion $source): Collection
    {
        if ($source === null) {
            return collect();
        }

        return $source->presentCheckouts(RepositoryType::copiedIntoNewVersions())
            ->sortBy(fn (RepositoryVersion $checkout) => $checkout->repository?->name ?? '')
            ->values();
    }

    private function addCheckout(
        Deployment $deployment,
        Repository $repository,
        MediaWikiVersion $version,
        string $ref,
        RefMode $refMode,
    ): void {
        $checkout = ($this->registerCheckout)($repository, $version, $refMode, $ref);

        $deployment->repoRefs()->create([
            'repository_version_id' => $checkout->getKey(),
            'action' => RepoAction::Deploy->value,
            'ref_type' => RefType::detect($ref)->value,
            'ref_value' => $ref,
        ]);
    }

    /**
     * @return array{version: null, deployment: null, error: string}
     */
    private function failure(string $error): array
    {
        return ['version' => null, 'deployment' => null, 'error' => $error];
    }
}
