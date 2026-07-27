<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DeploymentDecision;
use App\Models\Deployment;
use App\Services\Deployment\DecisionGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Answers the blocking canary prompt (section 4.3). The queued job is sitting in
 * a poll loop waiting for this row to change; there is no direct channel from
 * the browser to the job, and there does not need to be.
 */
final class DeploymentDecisionController extends Controller
{
    public function store(Request $request, Deployment $deployment, DecisionGate $gate): RedirectResponse
    {
        $this->authorize('decide', $deployment);

        $validated = $request->validate([
            'decision' => ['required', Rule::enum(DeploymentDecision::class)],
        ]);

        $decision = DeploymentDecision::from($validated['decision']);

        $gate->record($deployment, $decision, $request->user()->getKey());

        return back()->with('status', 'Recorded: '.$decision->label().'. The deployment will pick this up shortly.');
    }
}
