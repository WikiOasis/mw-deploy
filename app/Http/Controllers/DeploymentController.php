<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Sections 4.3 and 4.4: the live dashboard and the history the old JSON state
 * file could never give you.
 */
final class DeploymentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Deployment::class);

        $status = DeploymentStatus::tryFrom((string) $request->query('status', ''));

        return view('deployments.index', [
            'deployments' => Deployment::query()
                ->with(['creator', 'repoRefs.repository', 'rollsBack', 'rollbacks'])
                ->when($status !== null, fn ($query) => $query->where('status', $status->value))
                ->latest('id')
                ->paginate(25)
                ->withQueryString(),
            'selectedStatus' => $status,
            'statuses' => DeploymentStatus::cases(),
        ]);
    }

    public function show(Deployment $deployment): View
    {
        $this->authorize('view', $deployment);

        $deployment->load([
            'creator', 'decidedBy', 'rollsBack', 'rollbacks',
            'repoRefs.repository', 'snapshots.repository',
            'deploymentPatches.patch', 'steps',
        ]);

        return view('deployments.show', [
            'deployment' => $deployment,
            'stagingHost' => (string) config('mwdeploy.targets.staging'),
            'stepsByHost' => $deployment->stepsByHost(),
            'newerDeployments' => $this->newerDeploymentsTouchingSameRepos($deployment),
        ]);
    }

    /**
     * Polling fallback for the live dashboard. Echo is the primary channel, but a
     * deploy is exactly the wrong time to discover the websocket is down.
     */
    public function state(Deployment $deployment): JsonResponse
    {
        $this->authorize('view', $deployment);

        $deployment->load('steps');

        return response()->json([
            'id' => $deployment->getKey(),
            'status' => $deployment->status->value,
            'awaiting_decision' => $deployment->awaitingDecision(),
            'pending_decision' => $deployment->pending_decision?->value,
            'pending_decision_context' => $deployment->pending_decision_context,
            'failure_reason' => $deployment->failure_reason,
            'duration' => $deployment->durationSeconds(),
            'steps' => $deployment->steps
                ->sortBy([['sequence', 'asc'], ['id', 'asc']])
                ->map(fn ($step) => [
                    'id' => $step->getKey(),
                    'host' => $step->target_hostname,
                    'step_name' => $step->step_name->value,
                    'label' => $step->label(),
                    'status' => $step->status->value,
                    'elapsed' => $step->elapsedSeconds(),
                    'log' => $step->log,
                ])
                ->values(),
        ]);
    }

    /**
     * Deployments that have touched the same repositories since this one, so the
     * history view can warn before an out-of-order rollback (section 6.2).
     *
     * @return Collection<int, Deployment>
     */
    private function newerDeploymentsTouchingSameRepos(Deployment $deployment): Collection
    {
        $repositoryIds = $deployment->repoRefs->pluck('repository_id')->all();

        if ($repositoryIds === []) {
            return collect();
        }

        return Deployment::query()
            ->where('id', '>', $deployment->getKey())
            ->whereHas('repoRefs', fn ($query) => $query->whereIn('repository_id', $repositoryIds))
            ->orderBy('id')
            ->get();
    }
}
