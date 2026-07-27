<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Deployments\RollbackDeployment;
use App\Models\Deployment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Section 6.2's manual path: the "Roll back" button on any past deployment, for
 * when a deploy passed canary but a problem surfaced an hour later.
 */
final class DeploymentRollbackController extends Controller
{
    public function store(Request $request, Deployment $deployment, RollbackDeployment $rollback): RedirectResponse
    {
        $this->authorize('rollback', $deployment);

        $created = $rollback(failed: $deployment, actor: $request->user());

        if ($created === null) {
            return back()->withErrors([
                'rollback' => 'This deployment recorded no usable undo point, so it cannot be rolled back automatically.',
            ]);
        }

        return redirect()
            ->route('deployments.show', $created)
            ->with('status', 'Rollback #'.$created->getKey().' queued, reverting deployment #'.$deployment->getKey().'.');
    }
}
