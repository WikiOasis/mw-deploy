<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Deployments\RollbackDeployment;
use App\Enums\DeploymentDecision;
use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Services\Deployment\DecisionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The blocking canary prompt, and the manual rollback button.
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
}
