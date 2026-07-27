<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TargetRole;
use App\Models\DeployTarget;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The deploy target inventory. `hostname` must match the Salt minion id exactly,
 * because that string is what gets passed as the Salt target (open question 4).
 */
final class DeployTargetController extends Controller
{
    public function index(): View
    {
        $this->authorize(Permissions::TARGETS_MANAGE);

        return view('targets.index', [
            'targetsByRole' => DeployTarget::query()
                ->orderBy('role')
                ->orderBy('sort_order')
                ->orderBy('hostname')
                ->get()
                ->groupBy(fn (DeployTarget $target) => $target->role->value),
            'roles' => TargetRole::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permissions::TARGETS_MANAGE);

        $validated = $request->validate($this->rules());

        DeployTarget::create([
            ...$validated,
            'active' => $request->boolean('active', true),
        ]);

        return back()->with('status', 'Target '.$validated['hostname'].' added.');
    }

    public function update(Request $request, DeployTarget $target): RedirectResponse
    {
        $this->authorize(Permissions::TARGETS_MANAGE);

        $validated = $request->validate($this->rules($target));

        $target->update([
            ...$validated,
            'active' => $request->boolean('active'),
        ]);

        return back()->with('status', 'Target '.$target->hostname.' updated.');
    }

    public function destroy(DeployTarget $target): RedirectResponse
    {
        $this->authorize(Permissions::TARGETS_MANAGE);

        // Deactivate: deployment_steps reference the hostname, and history has to
        // keep resolving after a box is decommissioned.
        $target->update(['active' => false]);

        return back()->with('status', 'Target '.$target->hostname.' deactivated.');
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
