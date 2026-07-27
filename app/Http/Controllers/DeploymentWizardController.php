<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Deployments\CreateDeployment;
use App\Enums\DeploymentIntent;
use App\Enums\RepoAction;
use App\Enums\RepositoryType;
use App\Enums\TargetRole;
use App\Http\Requests\StoreDeploymentRequest;
use App\Models\Deployment;
use App\Models\DeploymentRepoRef;
use App\Models\DeployTarget;
use App\Models\MediaWikiVersion;
use App\Models\Patch;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use App\Services\Deployment\DeploymentPlanner;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;

/**
 * The deploy and undeploy wizards.
 *
 * One form, two intents. What differs is which repositories are offered (deploy vs
 * undeploy permissions are separate grants), whether refs are collected, and how
 * loudly the review screen shouts.
 */
final class DeploymentWizardController extends Controller
{
    public function create(): View
    {
        $this->authorize('create', Deployment::class);

        return view('deployments.create', $this->wizardData(DeploymentIntent::Deploy));
    }

    /**
     * The undeploy flow. Deliberately a separate screen rather than a mode toggle
     * on the deploy form: removing checkouts off the whole fleet should not be one
     * mis-click away from updating them.
     */
    public function createUndeploy(): View
    {
        $user = request()->user();

        abort_unless($user->hasAnyPermission(Permissions::anyUndeploy()), 403);

        return view('deployments.undeploy', $this->wizardData(DeploymentIntent::Undeploy));
    }

    /**
     * Show the exact sequence of Salt calls that will run, in order. This is a
     * destructive multi-server action; it should not be a surprise.
     */
    public function review(StoreDeploymentRequest $request, DeploymentPlanner $planner): View
    {
        $intent = $request->intent();
        $byId = $request->checkouts()->keyBy('id');

        $selections = [];

        foreach ($request->items() as $item) {
            $checkout = $byId->get($item['repository_version_id']);

            if ($checkout === null) {
                continue;
            }

            $selections[] = [
                'checkout' => $checkout,
                'action' => RepoAction::from($item['action']),
                'ref_type' => $item['ref_type'],
                'ref_value' => $item['ref_value'],
            ];
        }

        $refs = $planner->refsFromSelections($selections);
        $patches = $request->patches();
        $options = $request->options();

        return view('deployments.review', [
            'planned' => collect($planner->plan($refs, $patches, $options, $intent))->groupBy('phase'),
            'refs' => $refs,
            'patches' => $patches,
            'options' => $options,
            'intent' => $intent,
            'version' => null,
            'payload' => $this->payload($request),
            'unselectedPatches' => $this->unselectedPatches($refs, $patches, $intent),
        ]);
    }

    public function store(StoreDeploymentRequest $request, CreateDeployment $create): RedirectResponse
    {
        $deployment = $create(
            actor: $request->user(),
            refs: $request->items(),
            patchIds: $request->patchIds(),
            options: $request->options(),
            intent: $request->intent(),
        );

        return redirect()
            ->route('deployments.show', $deployment)
            ->with('status', 'Deployment #'.$deployment->getKey().' queued.');
    }

    /**
     * Repositories, their checkouts and the version list, filtered to what this
     * user may act on under the given intent.
     *
     * @return array<string, mixed>
     */
    private function wizardData(DeploymentIntent $intent): array
    {
        $user = request()->user();
        $action = $intent->defaultAction();

        $repositories = $user->actionableRepositories(
            Repository::query()->active()->with(['versions.mediawikiVersion'])->orderBy('type')->orderBy('name')->get(),
            $action,
        );

        // Under an undeploy only checkouts that are actually on disk can go; under
        // a deploy an absent one is legitimate — that is how you restore it.
        $checkouts = $repositories
            ->mapWithKeys(fn (Repository $repository) => [
                $repository->getKey() => $repository->versions
                    ->when(
                        $action === RepoAction::Undeploy,
                        fn (Collection $versions) => $versions->filter(
                            fn (RepositoryVersion $checkout) => $checkout->isPresent()
                        )
                    )
                    ->sortByDesc(fn (RepositoryVersion $checkout) => $checkout->mediawikiVersion?->version ?? '')
                    ->values(),
            ])
            ->filter(fn (Collection $versions) => $versions->isNotEmpty());

        // Drop repositories left with nothing selectable.
        $repositories = $repositories->filter(
            fn (Repository $repository) => $checkouts->has($repository->getKey())
        )->values();

        return [
            'intent' => $intent,
            'repositoriesByType' => $repositories->groupBy(fn (Repository $r) => $r->type->value),
            'checkoutsByRepository' => $checkouts,
            'types' => RepositoryType::cases(),
            'versions' => MediaWikiVersion::query()->orderByDesc('version')->get(),
            'patches' => $intent === DeploymentIntent::Undeploy
                ? collect()
                : Patch::query()->active()->with('targetCheckout.repository')->orderBy('name')->get(),
            'appservers' => DeployTarget::query()->active()->role(TargetRole::Appserver)
                ->orderBy('sort_order')->orderBy('hostname')->get(),
            'proxies' => DeployTarget::query()->active()->role(TargetRole::Proxy)
                ->orderBy('sort_order')->orderBy('hostname')->get(),
            'maxParallel' => (int) config('mwdeploy.rollout.max_parallel', 8),
            'defaultParallel' => (int) config('mwdeploy.rollout.default_parallel', 1),
            'canForce' => $user->hasPermission(Permissions::DEPLOY_FORCE_FLAG),
            'canTargetProduction' => $user->hasPermission(Permissions::DEPLOY_PRODUCTION_SERVERS),
        ];
    }

    /**
     * Re-serialise the validated input so the review screen can POST the very same
     * payload on to store() without a second round of user editing.
     *
     * @return array<string, mixed>
     */
    private function payload(StoreDeploymentRequest $request): array
    {
        $options = $request->options();

        return [
            'intent' => $request->intent()->value,
            'items' => $request->items(),
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
     * Active patches for the checkouts in this deployment that the operator did
     * not tick. Surfaced on review so a patch is not silently dropped just because
     * someone forgot it.
     *
     * @param  Collection<int, DeploymentRepoRef>  $refs
     * @param  Collection<int, Patch>  $selected
     * @return Collection<int, Patch>
     */
    private function unselectedPatches(Collection $refs, Collection $selected, DeploymentIntent $intent): Collection
    {
        if ($intent === DeploymentIntent::Undeploy) {
            return collect();
        }

        $checkoutIds = $refs->pluck('repository_version_id')->filter()->all();

        if ($checkoutIds === []) {
            return collect();
        }

        $selectedIds = $selected->modelKeys();

        return Patch::query()
            ->active()
            ->whereIn('target_repository_version_id', $checkoutIds)
            ->when($selectedIds !== [], fn ($query) => $query->whereKeyNot($selectedIds))
            ->orderBy('name')
            ->get();
    }
}
