<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StepName;
use App\Enums\TargetRole;
use App\Models\DeployTarget;
use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\ShimCalls;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Manual depool/repool, the web equivalent of the curses TUI's Ctrl+R menu.
 *
 * Loops over the proxy inventory here rather than letting the shim fan out, so a
 * single proxy failing is attributable to that proxy.
 */
final class PoolController extends Controller
{
    public function store(
        Request $request,
        DeployTarget $target,
        SaltClient $salt,
        ShimCalls $calls,
    ): RedirectResponse {
        $this->authorize(Permissions::DEPLOY_POOL);

        $validated = $request->validate([
            'action' => ['required', 'in:depool,repool'],
        ]);

        if ($target->role !== TargetRole::Appserver) {
            return back()->withErrors(['action' => 'Only appservers can be pooled or depooled.']);
        }

        $step = $validated['action'] === 'depool'
            ? StepName::HaproxyDepool
            : StepName::HaproxyRepool;

        $proxies = DeployTarget::query()
            ->active()
            ->role(TargetRole::Proxy)
            ->orderBy('sort_order')
            ->orderBy('hostname')
            ->get();

        if ($proxies->isEmpty()) {
            return back()->withErrors(['action' => 'No active proxies are registered.']);
        }

        $failures = [];

        foreach ($proxies as $proxy) {
            $result = $salt->run($calls->haproxy($step, $proxy, $target));

            if (! $result->ok) {
                $failures[] = $proxy->hostname.': '.$result->detail();
            }
        }

        if ($failures !== []) {
            return back()->withErrors([
                'action' => ucfirst($validated['action']).' failed on '.implode('; ', $failures),
            ]);
        }

        return back()->with(
            'status',
            $target->hostname.' '.($validated['action'] === 'depool' ? 'depooled from' : 'repooled into')
                .' '.$proxies->count().' proxy/proxies.',
        );
    }
}
