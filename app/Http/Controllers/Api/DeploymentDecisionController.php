<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Deployments\ForceFailDeployment;
use App\Actions\Deployments\RollbackDeployment;
use App\Enums\DeploymentDecision;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentProgressed;
use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Services\Deployment\DecisionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The blocking canary prompt, the manual rollback button, and the three ways
 * to stop a deployment that has not finished on its own: cancelling one that
 * has not started yet, aborting one that is running, and force-failing one
 * the pipeline itself will never resolve because its worker is gone.
 *
 * Answering a prompt writes a row the queued job is polling for; there is no
 * direct channel from the browser to the job, and there does not need to be.
 */
final class DeploymentDecisionController extends Controller
{
    public function store(Request $request, Deployment $deployment, DecisionGate $gate): JsonResponse
    {
        $this->authorize('decide', $deployment);

        $validated = $request->validate([
            'decision' => ['required', Rule::enum(DeploymentDecision::class)],
        ]);

        $decision = DeploymentDecision::from($validated['decision']);

        $gate->record($deployment, $decision, $request->user()->getKey());

        return response()->json([
            'message' => 'Recorded: '.$decision->label().'. The deployment will pick this up shortly.',
            'decision' => $decision->value,
        ]);
    }

    public function rollback(Request $request, Deployment $deployment, RollbackDeployment $rollback): JsonResponse
    {
        $this->authorize('rollback', $deployment);

        $created = $rollback(failed: $deployment, actor: $request->user());

        if ($created === null) {
            throw ValidationException::withMessages([
                'rollback' => 'This deployment recorded no usable undo point, so it cannot be rolled back automatically.',
            ]);
        }

        return response()->json([
            'id' => $created->getKey(),
            'message' => 'Rollback #'.$created->getKey().' queued, reverting deployment #'.$deployment->getKey().'.',
        ], 201);
    }

    /**
     * Cancel a deployment that is still queued. Guarded atomically against the
     * worker picking it up in the same instant: only a row still Pending is
     * updated, so a deployment that has already started is left to the abort
     * endpoint instead of being silently left half-cancelled.
     */
    public function cancel(Request $request, Deployment $deployment): JsonResponse
    {
        $this->authorize('cancel', $deployment);

        $cancelled = Deployment::query()
            ->whereKey($deployment->getKey())
            ->where('status', DeploymentStatus::Pending->value)
            ->update([
                'status' => DeploymentStatus::Aborted->value,
                'failure_reason' => 'Cancelled by '.$request->user()->name.' before it started.',
                'finished_at' => now(),
            ]);

        if ($cancelled === 0) {
            throw ValidationException::withMessages([
                'cancel' => 'This deployment already started, so it can no longer be cancelled — abort it instead.',
            ]);
        }

        DeploymentProgressed::dispatch($deployment->fresh());

        return response()->json([
            'message' => 'Deployment #'.$deployment->getKey().' cancelled before it started.',
        ]);
    }

    /**
     * Request that a running deployment stop at its next safe checkpoint. Unlike
     * decision(), this does not require a prompt to already be open — the runner
     * polls for it between steps regardless of what it happens to be doing.
     */
    public function abort(Request $request, Deployment $deployment, DecisionGate $gate): JsonResponse
    {
        $this->authorize('abort', $deployment);

        $validated = $request->validate([
            'decision' => ['required', Rule::in([
                DeploymentDecision::Abort->value,
                DeploymentDecision::AbortAndRollback->value,
            ])],
        ]);

        $decision = DeploymentDecision::from($validated['decision']);

        $requested = $gate->requestAbort(
            $deployment,
            $decision === DeploymentDecision::AbortAndRollback,
            $request->user()->getKey(),
        );

        if (! $requested) {
            throw ValidationException::withMessages([
                'abort' => 'This deployment is no longer running, so there is nothing left to abort.',
            ]);
        }

        return response()->json([
            'message' => 'Abort requested: '.$decision->label().'. The deployment will stop at its next safe checkpoint.',
        ]);
    }

    /**
     * Force-fail a deployment the pipeline itself will never resolve: no live
     * worker is polling for anything, so unlike abort/cancel this acts
     * immediately and unilaterally rather than requesting something a job loop
     * picks up.
     */
    public function forceFail(Request $request, Deployment $deployment, ForceFailDeployment $forceFail): JsonResponse
    {
        $this->authorize('forceFail', $deployment);

        $forceFail($deployment, $request->user());

        return response()->json([
            'message' => 'Deployment #'.$deployment->getKey().' force-failed and the fleet deploy lock released.',
        ]);
    }
}
