<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Repositories\RegisterRepository;
use App\Enums\RepositoryType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRepositoryRequest;
use App\Http\Resources\RepositoryResource;
use App\Models\GitRefCache;
use App\Models\MediaWikiVersion;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use App\Services\Git\Contracts\GitRefProvider;
use App\Services\Git\GitRef;
use App\Support\DeploymentOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The repository registry: browsing it is open to anyone with an account, changing
 * it needs repositories.manage — adding a repository means new code will run on
 * every appserver, which is a different trust decision from deploying what is
 * already registered.
 */
final class RepositoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Repository::class);

        $search = trim((string) $request->query('q', ''));
        $type = RepositoryType::tryFrom((string) $request->query('type', ''));
        $version = MediaWikiVersion::query()->find($request->query('version'));

        $repositories = Repository::query()
            ->with(['versions.mediawikiVersion', 'scopedPermissions'])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->when($type !== null, fn ($query) => $query->where('type', $type->value))
            ->when($request->boolean('in_use'), fn ($query) => $query->where('in_use', true))
            ->when($request->boolean('imported'), fn ($query) => $query->whereNotNull('discovered_at'))
            ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('active', true))
            ->when($version !== null, fn ($query) => $query->whereHas(
                'versions',
                fn ($sub) => $sub->where('mediawiki_version_id', $version->getKey())->present(),
            ))
            ->orderBy('type')
            ->orderBy('name')
            ->paginate((int) min(200, max(10, (int) $request->query('per_page', 50))));

        return response()->json([
            'data' => RepositoryResource::collection($repositories->getCollection())->resolve(),
            'meta' => [
                'current_page' => $repositories->currentPage(),
                'last_page' => $repositories->lastPage(),
                'per_page' => $repositories->perPage(),
                'total' => $repositories->total(),
            ],
            'filters' => [
                'q' => $search,
                'type' => $type?->value,
                'version' => $version?->getKey(),
                'in_use' => $request->boolean('in_use'),
                'imported' => $request->boolean('imported'),
            ],
            'versions' => MediaWikiVersion::query()->orderByDesc('version')->get()
                ->map(fn (MediaWikiVersion $each): array => [
                    'id' => $each->getKey(),
                    'version' => $each->version,
                ])->all(),
        ]);
    }

    public function show(Repository $repository, GitRefProvider $refs): JsonResponse
    {
        $this->authorize('view', $repository);

        $repository->load(['versions.mediawikiVersion', 'versions.patches', 'creator', 'scopedPermissions']);

        // Branch and commit listings come from any checkout that is actually on
        // disk — they all share one remote, so any present clone will do.
        $readable = $repository->versions->first(
            fn (RepositoryVersion $checkout): bool => $checkout->isPresent()
        );

        return response()->json([
            'data' => (new RepositoryResource($repository))->resolve(),
            'refs' => [
                'available' => $refs->isAvailable(),
                'readable_checkout_id' => $readable?->getKey(),
                'branches' => $readable === null ? [] : $this->refPayload($refs->branches($readable)),
                'commits' => $readable === null ? [] : $this->refPayload($refs->commits($readable)),
            ],
        ]);
    }

    /**
     * Branch and commit listings for the wizard's ref picker, per checkout.
     *
     * Served from the persistent ref cache (see CachedGitRefProvider) — there is
     * no TTL here, so `fetched_at` is what tells the operator how stale this is,
     * and POST .../refs/fetch is the only thing that moves it forward.
     */
    public function refs(Request $request, RepositoryVersion $checkout, GitRefProvider $provider): JsonResponse
    {
        $this->authorize('view', $checkout->repository ?? Repository::class);

        $branch = $request->query('branch');
        $branch = is_string($branch) && $branch !== '' ? $branch : null;

        // Resolved before reading fetched_at: on a checkout's very first view
        // these are what populate the cache row in the first place, so reading
        // the timestamp first would always see "never fetched".
        $branches = $provider->branches($checkout);
        $commits = $provider->commits($checkout, $branch);

        return response()->json([
            'available' => $provider->isAvailable(),
            'present' => $checkout->isPresent(),
            'resolved_ref' => $checkout->resolvedRefValue(),
            'default_branch' => $checkout->repository?->default_branch,
            'observed_ref' => $checkout->observed_ref_value,
            'fetched_at' => $this->fetchedAt($checkout),
            'branches' => array_map(
                fn (GitRef $ref): array => [
                    'value' => $ref->value,
                    'label' => $ref->value,
                    'is_default' => $ref->isDefault,
                ],
                $branches,
            ),
            'commits' => array_map(
                fn (GitRef $ref): array => [
                    'value' => $ref->value,
                    'label' => $ref->short().' — '.($ref->subject ?? ''),
                    'author' => $ref->author,
                    'date' => $ref->date,
                ],
                $commits,
            ),
        ]);
    }

    /**
     * Force a live fetch + re-list, bypassing the persistent cache. What the
     * picker's "Fetch latest" button calls.
     */
    public function fetchRefs(Request $request, RepositoryVersion $checkout, GitRefProvider $provider): JsonResponse
    {
        $this->authorize('view', $checkout->repository ?? Repository::class);

        $provider->refresh($checkout);

        return $this->refs($request, $checkout, $provider);
    }

    private function fetchedAt(RepositoryVersion $checkout): ?string
    {
        $cache = GitRefCache::query()
            ->where('repository_version_id', $checkout->getKey())
            ->where('kind', 'branches')
            ->where('branch', '')
            ->first();

        return $cache?->fetched_at?->toIso8601String();
    }

    public function store(StoreRepositoryRequest $request, RegisterRepository $register): JsonResponse
    {
        $outcome = $register(
            attributes: $request->safe()->only(['name', 'type', 'git_url', 'default_branch', 'in_use']),
            actor: $request->user(),
            versionIds: $request->versionIds(),
            refs: $request->refOverrides(),
            // Registering does not touch production: the clone lands on staging,
            // and rolling it out is a separate, deliberate deployment.
            options: new DeploymentOptions(stagingOnly: true),
        );

        if ($outcome['error'] !== null) {
            throw ValidationException::withMessages(['git_url' => $outcome['error']]);
        }

        return response()->json([
            'repository' => (new RepositoryResource($outcome['repository']))->resolve(),
            'deployment_id' => $outcome['deployment']?->getKey(),
            'message' => $outcome['deployment'] === null
                ? $outcome['repository']->name.' registered.'
                : sprintf(
                    '%s registered. Cloning %d checkout(s) onto staging.',
                    $outcome['repository']->name,
                    $outcome['deployment']->repoRefs()->count(),
                ),
        ], 201);
    }

    /**
     * Metadata only. Checkout paths are deliberately immutable: moving a live
     * checkout is a filesystem operation, and these paths are what `repo-remove`
     * is pointed at.
     */
    public function update(StoreRepositoryRequest $request, Repository $repository): JsonResponse
    {
        $this->authorize('update', $repository);

        $repository->update([
            'git_url' => $request->validated('git_url'),
            'default_branch' => $request->validated('default_branch'),
            'in_use' => $request->boolean('in_use'),
        ]);

        $repository->load(['versions.mediawikiVersion', 'creator']);

        return response()->json([
            'repository' => (new RepositoryResource($repository))->resolve(),
            'message' => $repository->name.' updated.',
        ]);
    }

    /**
     * Deactivate rather than delete, and deliberately *not* an undeploy: this only
     * hides the repository from the wizard. Removing it from disk is an undeploy,
     * which needs its own permission and leaves an audit trail.
     */
    public function destroy(Repository $repository): JsonResponse
    {
        $this->authorize('delete', $repository);

        $repository->update(['active' => false]);

        $stillDeployed = $repository->versions()->present()->count();

        return response()->json([
            'message' => $stillDeployed === 0
                ? $repository->name.' deactivated. Past deployments still reference it.'
                : $repository->name.' deactivated, but '.$stillDeployed.' checkout(s) are still on disk. '
                    .'Use Undeploy to remove them from the servers.',
            'still_deployed' => $stillDeployed,
        ]);
    }

    /**
     * @param  list<GitRef>  $refs
     * @return list<array<string, mixed>>
     */
    private function refPayload(array $refs): array
    {
        return array_map(
            fn (GitRef $ref): array => [
                'value' => $ref->value,
                'short' => $ref->short(),
                'subject' => $ref->subject,
                'author' => $ref->author,
                'date' => $ref->date,
                'is_default' => $ref->isDefault,
            ],
            $refs,
        );
    }
}
