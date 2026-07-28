<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DeploymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeploymentResource;
use App\Models\Deployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * History and the per-deployment live view.
 */
final class DeploymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Deployment::class);

        $status = DeploymentStatus::tryFrom((string) $request->query('status', ''));

        $deployments = Deployment::query()
            ->with([
                'creator', 'mediawikiVersion', 'rollsBack', 'rollbacks',
                'repoRefs.repositoryVersion.repository', 'repoRefs.repositoryVersion.mediawikiVersion',
            ])
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->latest('id')
            ->paginate((int) min(100, max(5, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => DeploymentResource::collection($deployments->getCollection())->resolve(),
            'meta' => [
                'current_page' => $deployments->currentPage(),
                'last_page' => $deployments->lastPage(),
                'per_page' => $deployments->perPage(),
                'total' => $deployments->total(),
            ],
            'statuses' => array_map(
                static fn (DeploymentStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'classes' => $status->badgeClasses(),
                ],
                DeploymentStatus::cases(),
            ),
            'selected_status' => $status?->value,
        ]);
    }

    public function show(Deployment $deployment): JsonResponse
    {
        $this->authorize('view', $deployment);

        $deployment->load([
            'creator', 'decidedBy', 'abortRequestedBy', 'rollsBack', 'rollbacks', 'mediawikiVersion',
            'repoRefs.repositoryVersion.repository', 'repoRefs.repositoryVersion.mediawikiVersion',
            'snapshots.repositoryVersion.repository', 'snapshots.repositoryVersion.mediawikiVersion',
            'deploymentPatches.patch', 'steps',
        ]);

        return response()->json([
            'data' => (new DeploymentResource($deployment))->detailed()->resolve(),
            // Deployments that have touched the same checkouts since this one, so
            // the UI can warn before an out-of-order rollback.
            'newer_touching_same_repos' => $this->newerDeploymentsTouchingSameRepos($deployment)
                ->map(fn (Deployment $newer): array => [
                    'id' => $newer->getKey(),
                    'status' => $newer->status->value,
                    'summary' => $newer->summary(),
                    'created_at' => $newer->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Polling fallback for the live view. Echo is the primary channel, but a
     * deploy is exactly the wrong time to discover the websocket is down.
     */
    public function state(Deployment $deployment): JsonResponse
    {
        $this->authorize('view', $deployment);

        return response()->json(DeploymentResource::state($deployment));
    }

    /**
     * @return Collection<int, Deployment>
     */
    private function newerDeploymentsTouchingSameRepos(Deployment $deployment): Collection
    {
        $checkoutIds = $deployment->repoRefs->pluck('repository_version_id')->filter()->all();

        if ($checkoutIds === []) {
            return collect();
        }

        return Deployment::query()
            ->where('id', '>', $deployment->getKey())
            ->whereHas('repoRefs', fn ($query) => $query->whereIn('repository_version_id', $checkoutIds))
            ->with(['repoRefs.repositoryVersion.repository', 'repoRefs.repositoryVersion.mediawikiVersion'])
            ->orderBy('id')
            ->get();
    }
}
