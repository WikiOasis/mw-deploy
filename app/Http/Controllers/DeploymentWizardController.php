<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Deployments\CreateDeployment;
use App\Enums\RepositoryType;
use App\Enums\TargetRole;
use App\Http\Requests\StoreDeploymentRequest;
use App\Models\Deployment;
use App\Models\DeploymentRepoRef;
use App\Models\DeployTarget;
use App\Models\Patch;
use App\Models\Repository;
use App\Services\Deployment\DeploymentPlanner;
use App\Services\Git\Contracts\GitRefProvider;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;

/**
 * Section 4.2. One form with the six steps as sections, then a review screen
 * showing the literal Salt sequence before anything runs.
 */
final class DeploymentWizardController extends Controller
{
    public function create(GitRefProvider $refs): View
    {
        $this->authorize('create', Deployment::class);

        $user = request()->user();

        // Filtered to what this user may actually deploy, so the form cannot
        // offer a core version bump to an extension maintainer.
        $repositories = $user->deployableRepositories(
            Repository::query()->active()->orderBy('type')->orderBy('name')->get()
        );

        return view('deployments.create', [
            'repositoriesByType' => $repositories->groupBy(fn (Repository $r) => $r->type->value),
            'types' => RepositoryType::cases(),
            'branchesByRepository' => $repositories
                ->mapWithKeys(fn (Repository $r) => [$r->getKey() => $refs->branches($r)]),
            'discoveryAvailable' => $refs->isAvailable(),
            'patches' => Patch::query()->active()->with('targetRepository')->orderBy('name')->get(),
            'appservers' => DeployTarget::query()->active()->role(TargetRole::Appserver)
                ->orderBy('sort_order')->orderBy('hostname')->get(),
            'proxies' => DeployTarget::query()->active()->role(TargetRole::Proxy)
                ->orderBy('sort_order')->orderBy('hostname')->get(),
            'maxParallel' => (int) config('mwdeploy.rollout.max_parallel', 8),
            'defaultParallel' => (int) config('mwdeploy.rollout.default_parallel', 1),
            'canForce' => $user->hasPermission(Permissions::DEPLOY_FORCE_FLAG),
            'canTargetProduction' => $user->hasPermission(Permissions::DEPLOY_PRODUCTION_SERVERS),
        ]);
    }

    /**
     * Step 6: show the exact sequence of Salt calls that will run, in order.
     * This is a destructive multi-server action; it should not be a surprise.
     */
    public function review(StoreDeploymentRequest $request, DeploymentPlanner $planner): View
    {
        $options = $request->options();

        $byId = $request->repositories()->keyBy('id');

        $selections = [];

        foreach ($request->refs() as $ref) {
            $repository = $byId->get($ref['repository_id']);

            if ($repository === null) {
                continue;
            }

            $selections[] = [
                'repository' => $repository,
                'ref_type' => $ref['ref_type'],
                'ref_value' => $ref['ref_value'],
            ];
        }

        $refs = $planner->refsFromSelections($selections);
        $patches = $request->patches();

        return view('deployments.review', [
            'planned' => collect($planner->plan($refs, $patches, $options))->groupBy('phase'),
            'refs' => $refs,
            'patches' => $patches,
            'options' => $options,
            'payload' => $this->payload($request),
            'autoSelectedPatches' => $this->autoSelectedPatches($refs, $patches),
        ]);
    }

    public function store(StoreDeploymentRequest $request, CreateDeployment $create): RedirectResponse
    {
        $deployment = $create(
            actor: $request->user(),
            refs: $request->refs(),
            patchIds: $request->patchIds(),
            options: $request->options(),
        );

        return redirect()
            ->route('deployments.show', $deployment)
            ->with('status', 'Deployment #'.$deployment->getKey().' queued.');
    }

    /**
     * Re-serialise the validated input so the review screen can POST the very
     * same payload on to store() without a second round of user editing.
     *
     * @return array<string, mixed>
     */
    private function payload(StoreDeploymentRequest $request): array
    {
        $options = $request->options();

        return [
            'refs' => $request->refs(),
            'patches' => $request->patchIds(),
            'servers' => $options->servers,
            'parallel' => $options->parallel,
            'force' => $options->force,
            'l10n' => $options->l10n,
            'rollout' => $options->rollout,
            'staging_only' => $options->stagingOnly,
        ];
    }

    /**
     * Active patches for the repos in this deployment that the operator did not
     * tick. Surfaced on review so a patch is not silently dropped just because
     * someone forgot it — the failure mode section 4.5 exists to close.
     *
     * @param  Collection<int, DeploymentRepoRef>  $refs
     * @param  Collection<int, Patch>  $selected
     * @return Collection<int, Patch>
     */
    private function autoSelectedPatches(Collection $refs, Collection $selected): Collection
    {
        $repositoryIds = $refs->pluck('repository_id')->filter()->all();

        if ($repositoryIds === []) {
            return collect();
        }

        $selectedIds = $selected->modelKeys();

        return Patch::query()
            ->active()
            ->whereIn('target_repo_id', $repositoryIds)
            ->when($selectedIds !== [], fn ($query) => $query->whereKeyNot($selectedIds))
            ->orderBy('name')
            ->get();
    }
}
