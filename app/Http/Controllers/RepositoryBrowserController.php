<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RepositoryType;
use App\Models\MediaWikiVersion;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use App\Services\Git\Contracts\GitRefProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only browsing of the repository registry for anyone with an account.
 * Adding one lives behind repositories.manage in RepositoryRegistryController.
 */
final class RepositoryBrowserController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Repository::class);

        $search = trim((string) $request->query('q', ''));
        $type = RepositoryType::tryFrom((string) $request->query('type', ''));
        $version = MediaWikiVersion::query()->find($request->query('version'));

        $repositories = Repository::query()
            ->with(['versions.mediawikiVersion'])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->when($type !== null, fn ($query) => $query->where('type', $type->value))
            ->when($request->boolean('in_use'), fn ($query) => $query->where('in_use', true))
            ->when($version !== null, fn ($query) => $query->whereHas(
                'versions',
                fn ($sub) => $sub->where('mediawiki_version_id', $version->getKey())->present(),
            ))
            ->orderBy('type')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('repositories.index', [
            'repositories' => $repositories,
            'search' => $search,
            'selectedType' => $type,
            'selectedVersion' => $version,
            'inUseOnly' => $request->boolean('in_use'),
            'types' => RepositoryType::cases(),
            'versions' => MediaWikiVersion::query()->orderByDesc('version')->get(),
        ]);
    }

    public function show(Repository $repository, GitRefProvider $refs): View
    {
        $this->authorize('view', $repository);

        $repository->load(['versions.mediawikiVersion', 'versions.patches']);

        // Branch and commit listings come from any checkout that is actually on
        // disk — they all share one remote, so any present clone will do.
        $readable = $repository->versions->first(fn (RepositoryVersion $checkout) => $checkout->isPresent());

        return view('repositories.show', [
            'repository' => $repository,
            'checkouts' => $repository->versions
                ->sortByDesc(fn (RepositoryVersion $checkout) => $checkout->mediawikiVersion?->version ?? '')
                ->values(),
            'branches' => $readable === null ? [] : $refs->branches($readable),
            'commits' => $readable === null ? [] : $refs->commits($readable),
            'readableCheckout' => $readable,
            'discoveryAvailable' => $refs->isAvailable(),
        ]);
    }

    /**
     * Branch and commit listings for the wizard's ref picker, per checkout.
     */
    public function refs(Request $request, RepositoryVersion $checkout, GitRefProvider $provider): JsonResponse
    {
        $this->authorize('view', $checkout->repository ?? Repository::class);

        $branch = $request->query('branch');
        $branch = is_string($branch) && $branch !== '' ? $branch : null;

        return response()->json([
            'available' => $provider->isAvailable(),
            'present' => $checkout->isPresent(),
            'resolved_ref' => $checkout->resolvedRefValue(),
            'default_branch' => $checkout->repository?->default_branch,
            'branches' => array_map(
                fn ($ref) => ['value' => $ref->value, 'label' => $ref->value, 'is_default' => $ref->isDefault],
                $provider->branches($checkout),
            ),
            'commits' => array_map(
                fn ($ref) => [
                    'value' => $ref->value,
                    'label' => $ref->short().' — '.($ref->subject ?? ''),
                    'author' => $ref->author,
                    'date' => $ref->date,
                ],
                $provider->commits($checkout, $branch),
            ),
        ]);
    }
}
