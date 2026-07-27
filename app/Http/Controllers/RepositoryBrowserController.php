<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RepositoryType;
use App\Models\Repository;
use App\Services\Git\Contracts\GitRefProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Section 4.1: read-only browsing of the repository registry for anyone with an
 * account. Adding a repository lives behind repositories.manage in
 * RepositoryRegistryController.
 */
final class RepositoryBrowserController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Repository::class);

        $search = trim((string) $request->query('q', ''));
        $type = RepositoryType::tryFrom((string) $request->query('type', ''));

        $repositories = Repository::query()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->when($type !== null, fn ($query) => $query->where('type', $type->value))
            ->when($request->boolean('in_use'), fn ($query) => $query->where('in_use', true))
            ->orderBy('type')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('repositories.index', [
            'repositories' => $repositories,
            'search' => $search,
            'selectedType' => $type,
            'inUseOnly' => $request->boolean('in_use'),
            'types' => RepositoryType::cases(),
        ]);
    }

    public function show(Repository $repository, GitRefProvider $refs): View
    {
        $this->authorize('view', $repository);

        $branches = $refs->branches($repository);

        return view('repositories.show', [
            'repository' => $repository,
            'branches' => $branches,
            'commits' => $refs->commits($repository),
            'discoveryAvailable' => $refs->isAvailable(),
            'patches' => $repository->patches()->orderBy('name')->get(),
        ]);
    }

    /**
     * Branch and commit listings for the wizard's ref picker.
     */
    public function refs(Request $request, Repository $repository, GitRefProvider $provider): JsonResponse
    {
        $this->authorize('view', $repository);

        $branch = $request->query('branch');
        $branch = is_string($branch) && $branch !== '' ? $branch : null;

        return response()->json([
            'available' => $provider->isAvailable(),
            'default_branch' => $repository->default_branch,
            'branches' => array_map(
                fn ($ref) => ['value' => $ref->value, 'label' => $ref->value, 'is_default' => $ref->isDefault],
                $provider->branches($repository),
            ),
            'commits' => array_map(
                fn ($ref) => [
                    'value' => $ref->value,
                    'label' => $ref->short().' — '.($ref->subject ?? ''),
                    'author' => $ref->author,
                    'date' => $ref->date,
                ],
                $provider->commits($repository, $branch),
            ),
        ]);
    }
}
