<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Deployments\CreateDeployment;
use App\Enums\DeploymentIntent;
use App\Enums\RepoAction;
use App\Enums\RepositoryType;
use App\Enums\TargetRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeploymentRequest;
use App\Http\Resources\CheckoutResource;
use App\Http\Resources\PatchResource;
use App\Http\Resources\RepositoryResource;
use App\Http\Resources\TargetResource;
use App\Models\Deployment;
use App\Models\DeploymentRepoRef;
use App\Models\DeployTarget;
use App\Models\MediaWikiVersion;
use App\Models\Patch;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use App\Services\Deployment\DeploymentPlanner;
use App\Services\Deployment\PlannedCall;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The deploy and undeploy wizards, as data.
 *
 * Three endpoints for one flow: what may be selected, what the selection would
 * run, and go. The middle one matters — this is a destructive multi-server action,
 * and the exact Salt call sequence is shown and confirmed before anything is
 * queued. The plan is built by the same DeploymentPlanner the runner uses, so what
 * an operator confirms is literally what executes.
 */
final class DeploymentWizardController extends Controller
{
    /**
     * Repositories, checkouts, patches and targets, filtered to what this user may
     * act on under the requested intent.
     */
    public function options(Request $request): JsonResponse
    {
        $intent = DeploymentIntent::tryFrom((string) $request->query('intent', 'deploy')) ?? DeploymentIntent::Deploy;
        $user = $request->user();

        if ($intent === DeploymentIntent::Undeploy) {
            abort_unless($user->hasAnyPermission(Permissions::anyUndeploy()), 403);
        } else {
            $this->authorize('create', Deployment::class);
        }

        $action = $intent->defaultAction();

        $repositories = $user->actionableRepositories(
            Repository::query()
                ->active()
                ->with(['versions.mediawikiVersion', 'versions.patches', 'scopedPermissions'])
                ->orderBy('type')
                ->orderBy('name')
                ->get(),
            $action,
        );

        // Under an undeploy only checkouts that are actually on disk can go; under
        // a deploy an absent one is legitimate — that is how you restore it.
        $checkouts = $repositories
            ->mapWithKeys(fn (Repository $repository): array => [
                $repository->getKey() => $repository->versions
                    ->when(
                        $action === RepoAction::Undeploy,
                        fn (Collection $versions): Collection => $versions->filter(
                            fn (RepositoryVersion $checkout): bool => $checkout->isPresent()
                        )
                    )
                    ->sortByDesc(fn (RepositoryVersion $checkout): string => $checkout->mediawikiVersion?->version ?? '')
                    ->values(),
            ])
            ->filter(fn (Collection $versions): bool => $versions->isNotEmpty());

        $repositories = $repositories
            ->filter(fn (Repository $repository): bool => $checkouts->has($repository->getKey()))
            ->values();

        return response()->json([
            'intent' => $intent->value,
            'intent_label' => $intent->label(),
            'repositories' => $repositories->map(fn (Repository $repository): array => [
                ...(new RepositoryResource($repository))->resolve(),
                'checkouts' => CheckoutResource::collection(
                    $checkouts->get($repository->getKey(), collect())
                )->resolve(),
            ])->all(),
            'types' => array_map(
                static fn (RepositoryType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'plural_label' => $type->pluralLabel(),
                ],
                RepositoryType::cases(),
            ),
            'versions' => MediaWikiVersion::query()->orderByDesc('version')->get()
                ->map(fn (MediaWikiVersion $version): array => [
                    'id' => $version->getKey(),
                    'version' => $version->version,
                    'present' => $version->isPresent(),
                ])->all(),
            // Patches are meaningless on a removal, and the wizard does not offer
            // them there.
            'patches' => $intent === DeploymentIntent::Undeploy
                ? []
                : PatchResource::collection(
                    Patch::query()->active()->with(['targetCheckout.repository', 'targetCheckout.mediawikiVersion'])
                        ->orderBy('name')->get()
                )->resolve(),
            'appservers' => TargetResource::collection($this->targets(TargetRole::Appserver))->resolve(),
            'proxies' => TargetResource::collection($this->targets(TargetRole::Proxy))->resolve(),
            'defaults' => [
                'parallel' => (int) config('mwdeploy.rollout.default_parallel', 1),
                'max_parallel' => (int) config('mwdeploy.rollout.max_parallel', 8),
                // Someone who may not target production can only ever run
                // staging-only, so the toggle starts on and is fixed there.
                'staging_only' => ! $user->hasPermission(Permissions::DEPLOY_PRODUCTION_SERVERS),
            ],
            'can' => [
                'force' => $user->hasPermission(Permissions::DEPLOY_FORCE_FLAG),
                'target_production' => $user->hasPermission(Permissions::DEPLOY_PRODUCTION_SERVERS),
            ],
        ]);
    }

    /**
     * The exact sequence of Salt calls the submitted selection would run.
     */
    public function plan(StoreDeploymentRequest $request, DeploymentPlanner $planner): JsonResponse
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

        $planned = collect($planner->plan($refs, $patches, $options, $intent));

        return response()->json([
            'intent' => $intent->value,
            'intent_label' => $intent->label(),
            'removes_anything' => $refs->contains(
                fn (DeploymentRepoRef $ref): bool => $ref->action === RepoAction::Undeploy
            ),
            'phases' => $planned
                ->groupBy(fn (PlannedCall $call): string => $call->phase)
                ->map(fn (Collection $calls): array => $calls->map(fn (PlannedCall $call): array => [
                    'target' => $call->target(),
                    'label' => $call->label(),
                    'step' => $call->call->step()->value,
                    'command' => $call->commandLine(),
                ])->values()->all())
                ->all(),
            'call_count' => $planned->count(),
            'items' => $refs->map(fn (DeploymentRepoRef $ref): array => [
                'checkout_id' => $ref->repository_version_id,
                'name' => $ref->repositoryVersion?->displayName(),
                'path' => $ref->repositoryVersion?->path,
                'action' => $ref->action->value,
                'ref_value' => $ref->ref_value,
                'summary' => $ref->summary(),
            ])->values()->all(),
            'patches' => PatchResource::collection($patches)->resolve(),
            // Active patches for these checkouts that were *not* ticked. Surfaced
            // so a patch is not silently dropped because someone forgot it.
            'unselected_patches' => PatchResource::collection(
                $this->unselectedPatches($refs, $patches, $intent)
            )->resolve(),
            'options' => $options->toArray(),
            'option_flags' => $options->summaryFlags(),
            // Echoed back so the confirm step posts exactly what was planned,
            // without a second round of user editing.
            'payload' => [
                'intent' => $intent->value,
                'items' => $request->items(),
                'patches' => $request->patchIds(),
                'servers' => $options->servers,
                'parallel' => $options->parallel,
                'force' => $options->force,
                'l10n' => $options->l10n,
                'rollout' => $options->rollout,
                'staging_only' => $options->stagingOnly,
            ],
        ]);
    }

    public function store(StoreDeploymentRequest $request, CreateDeployment $create): JsonResponse
    {
        $deployment = $create(
            actor: $request->user(),
            refs: $request->items(),
            patchIds: $request->patchIds(),
            options: $request->options(),
            intent: $request->intent(),
        );

        return response()->json([
            'id' => $deployment->getKey(),
            'message' => 'Deployment #'.$deployment->getKey().' queued.',
        ], 201);
    }

    /**
     * @return Collection<int, DeployTarget>
     */
    private function targets(TargetRole $role): Collection
    {
        return DeployTarget::query()
            ->active()
            ->role($role)
            ->orderBy('sort_order')
            ->orderBy('hostname')
            ->get();
    }

    /**
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
            ->with(['targetCheckout.repository', 'targetCheckout.mediawikiVersion'])
            ->whereIn('target_repository_version_id', $checkoutIds)
            ->when($selectedIds !== [], fn ($query) => $query->whereKeyNot($selectedIds))
            ->orderBy('name')
            ->get();
    }
}
