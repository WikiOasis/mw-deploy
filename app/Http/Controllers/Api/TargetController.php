<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\StepName;
use App\Enums\TargetRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\TargetResource;
use App\Models\DeployTarget;
use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\ShimCalls;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The deploy target inventory, plus manual pooling.
 *
 * `hostname` must match the Salt minion id exactly, because that string is what
 * gets passed as the Salt target.
 */
final class TargetController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize(Permissions::TARGETS_MANAGE);

        return response()->json([
            'data' => TargetResource::collection(
                DeployTarget::query()->orderBy('role')->orderBy('sort_order')->orderBy('hostname')->get()
            )->resolve(),
            'roles' => array_map(
                static fn (TargetRole $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                ],
                TargetRole::cases(),
            ),
            'settings' => [
                'staging_host' => (string) config('mwdeploy.targets.staging'),
                'haproxy_backend' => (string) config('mwdeploy.rollout.haproxy_backend'),
                'canary_vhost' => (string) config('mwdeploy.rollout.canary_vhost'),
            ],
            'can' => [
                'pool' => request()->user()?->hasPermission(Permissions::DEPLOY_POOL) ?? false,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize(Permissions::TARGETS_MANAGE);

        $validated = $request->validate($this->rules());

        $target = DeployTarget::create([
            ...$validated,
            'active' => $request->boolean('active', true),
        ]);

        return response()->json([
            'target' => (new TargetResource($target))->resolve(),
            'message' => 'Target '.$target->hostname.' added.',
        ], 201);
    }

    public function update(Request $request, DeployTarget $target): JsonResponse
    {
        $this->authorize(Permissions::TARGETS_MANAGE);

        $validated = $request->validate($this->rules($target));

        $target->update([
            ...$validated,
            'active' => $request->boolean('active'),
        ]);

        return response()->json([
            'target' => (new TargetResource($target))->resolve(),
            'message' => 'Target '.$target->hostname.' updated.',
        ]);
    }

    public function destroy(DeployTarget $target): JsonResponse
    {
        $this->authorize(Permissions::TARGETS_MANAGE);

        // Deactivate: deployment_steps reference the hostname, and history has to
        // keep resolving after a box is decommissioned.
        $target->update(['active' => false]);

        return response()->json(['message' => 'Target '.$target->hostname.' deactivated.']);
    }

    /**
     * Manual depool/repool, the web equivalent of the curses TUI's Ctrl+R menu.
     *
     * Loops over the proxy inventory here rather than letting the shim fan out, so a
     * single proxy failing is attributable to that proxy.
     */
    public function pool(Request $request, DeployTarget $target, SaltClient $salt, ShimCalls $calls): JsonResponse
    {
        $this->authorize(Permissions::DEPLOY_POOL);

        $validated = $request->validate([
            'action' => ['required', 'in:depool,repool'],
        ]);

        if ($target->role !== TargetRole::Appserver) {
            throw ValidationException::withMessages([
                'action' => 'Only appservers can be pooled or depooled.',
            ]);
        }

        $step = $validated['action'] === 'depool' ? StepName::HaproxyDepool : StepName::HaproxyRepool;

        $proxies = DeployTarget::query()
            ->active()
            ->role(TargetRole::Proxy)
            ->orderBy('sort_order')
            ->orderBy('hostname')
            ->get();

        if ($proxies->isEmpty()) {
            throw ValidationException::withMessages(['action' => 'No active proxies are registered.']);
        }

        $failures = [];

        foreach ($proxies as $proxy) {
            $result = $salt->run($calls->haproxy($step, $proxy, $target));

            if (! $result->ok) {
                $failures[] = $proxy->hostname.': '.$result->detail();
            }
        }

        if ($failures !== []) {
            throw ValidationException::withMessages([
                'action' => ucfirst($validated['action']).' failed on '.implode('; ', $failures),
            ]);
        }

        return response()->json([
            'message' => $target->hostname.' '
                .($validated['action'] === 'depool' ? 'depooled from' : 'repooled into')
                .' '.$proxies->count().' proxy/proxies.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?DeployTarget $target = null): array
    {
        return [
            'hostname' => [
                'required', 'string', 'max:190', 'regex:/^[A-Za-z0-9._\-]+$/',
                Rule::unique('deploy_targets', 'hostname')->ignore($target),
            ],
            'role' => ['required', Rule::enum(TargetRole::class)],
            'haproxy_backend' => ['nullable', 'string', 'max:190'],
            'haproxy_server_name' => ['nullable', 'string', 'max:190'],
            'canary_vhost' => ['nullable', 'string', 'max:190'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
