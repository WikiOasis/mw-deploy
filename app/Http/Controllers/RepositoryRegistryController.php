<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Repositories\RegisterRepository;
use App\Enums\RepositoryType;
use App\Http\Requests\StoreRepositoryRequest;
use App\Models\MediaWikiVersion;
use App\Models\Repository;
use App\Support\DeploymentOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Gated behind repositories.manage: adding a repository is a trust decision
 * distinct from "can deploy", since it means new code will run on every appserver.
 */
final class RepositoryRegistryController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Repository::class);

        return view('repositories.create', [
            'types' => RepositoryType::cases(),
            'versions' => MediaWikiVersion::query()->active()->orderByDesc('version')->get(),
        ]);
    }

    public function store(StoreRepositoryRequest $request, RegisterRepository $register): RedirectResponse
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
            return back()->withInput()->withErrors(['git_url' => $outcome['error']]);
        }

        if ($outcome['deployment'] === null) {
            return redirect()
                ->route('repositories.show', $outcome['repository'])
                ->with('status', $outcome['repository']->name.' registered.');
        }

        return redirect()
            ->route('deployments.show', $outcome['deployment'])
            ->with('status', sprintf(
                '%s registered. Cloning %d checkout(s) onto staging.',
                $outcome['repository']->name,
                $outcome['deployment']->repoRefs()->count(),
            ));
    }

    public function edit(Repository $repository): View
    {
        $this->authorize('update', $repository);

        $repository->load('versions.mediawikiVersion');

        return view('repositories.edit', [
            'repository' => $repository,
            'types' => RepositoryType::cases(),
            'versions' => MediaWikiVersion::query()->active()->orderByDesc('version')->get(),
        ]);
    }

    /**
     * Metadata only. Checkout paths are deliberately immutable: moving a live
     * checkout is a filesystem operation, and these paths are what `repo-remove`
     * is pointed at.
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
            ->with('status', $repository->name.' updated.');
    }

    /**
     * Deactivate rather than delete, and deliberately *not* an undeploy: this only
     * hides the repository from the wizard. Removing it from disk is an undeploy,
     * which needs its own permission and leaves an audit trail.
     */
    public function destroy(Repository $repository): RedirectResponse
    {
        $this->authorize('delete', $repository);

        $repository->update(['active' => false]);

        $stillDeployed = $repository->versions()->present()->count();

        return redirect()
            ->route('repositories.index')
            ->with('status', $stillDeployed === 0
                ? $repository->name.' deactivated. Past deployments still reference it.'
                : $repository->name.' deactivated, but '.$stillDeployed.' checkout(s) are still on disk. '
                    .'Use Undeploy to remove them from the servers.');
    }
}
