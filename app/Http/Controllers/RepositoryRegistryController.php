<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Repositories\RegisterRepository;
use App\Enums\RepositoryType;
use App\Http\Requests\StoreRepositoryRequest;
use App\Models\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Section 4.5, gated behind repositories.manage: adding a new extension, skin,
 * config repo or MediaWiki core version is a trust decision distinct from
 * "can deploy", since it means new code will run on every appserver.
 */
final class RepositoryRegistryController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Repository::class);

        return view('repositories.create', [
            'types' => RepositoryType::cases(),
            'coreVersions' => $this->knownCoreVersions(),
        ]);
    }

    public function store(StoreRepositoryRequest $request, RegisterRepository $register): RedirectResponse
    {
        /** @var array{repository: Repository|null, error: string|null, detail: string|null} $outcome */
        $outcome = $register($request->validated(), $request->user());

        if ($outcome['repository'] === null) {
            return back()
                ->withInput()
                ->withErrors(['git_url' => trim($outcome['error'].' '.($outcome['detail'] ?? ''))]);
        }

        return redirect()
            ->route('repositories.show', $outcome['repository'])
            ->with('status', $outcome['repository']->displayName().' registered and cloned into staging.');
    }

    public function edit(Repository $repository): View
    {
        $this->authorize('update', $repository);

        return view('repositories.edit', [
            'repository' => $repository,
            'types' => RepositoryType::cases(),
            'coreVersions' => $this->knownCoreVersions(),
        ]);
    }

    /**
     * Metadata only. The staging path is deliberately immutable: moving a live
     * checkout is a filesystem operation, not a form field.
     */
    public function update(StoreRepositoryRequest $request, Repository $repository): RedirectResponse
    {
        $this->authorize('update', $repository);

        $repository->update([
            'git_url' => $request->validated('git_url'),
            'default_branch' => $request->validated('default_branch'),
            'in_use' => $request->boolean('in_use'),
        ]);

        return redirect()
            ->route('repositories.show', $repository)
            ->with('status', $repository->displayName().' updated.');
    }

    /**
     * Deactivate rather than delete: past deployments reference this row, and
     * history has to keep resolving.
     */
    public function destroy(Repository $repository): RedirectResponse
    {
        $this->authorize('delete', $repository);

        $repository->update(['active' => false]);

        return redirect()
            ->route('repositories.index')
            ->with('status', $repository->displayName().' deactivated. Past deployments still reference it.');
    }

    /**
     * @return list<string>
     */
    private function knownCoreVersions(): array
    {
        return Repository::query()
            ->whereNotNull('core_version')
            ->distinct()
            ->orderByDesc('core_version')
            ->pluck('core_version')
            ->map(fn ($version) => (string) $version)
            ->all();
    }
}
