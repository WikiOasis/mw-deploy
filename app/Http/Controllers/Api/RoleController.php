<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Roles: the unit access is granted in.
 *
 * A role is a bundle of permissions, and because every permission belongs to
 * exactly one app, editing a role is how an app is handed out — tick
 * `apps.deployments.access` and the role's members see the Deployments tile;
 * tick `deploy.core` and they can use it.
 *
 * Gated on roles.manage rather than users.manage: putting someone into an
 * existing role is a smaller act than redefining what that role may do.
 */
final class RoleController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Role::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('roles', 'name')],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(Permissions::all()))],
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        $role->permissions()->sync($this->idsFor($validated['permissions'] ?? []));

        return response()->json([
            'role' => $this->present($role->fresh(['permissions'])),
            'message' => 'Role '.$role->name.' created.',
        ], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->authorize('update', $role);

        $validated = $request->validate([
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array'],
            // Only names the console actually knows: a typo would otherwise
            // become a permission row nothing ever checks.
            'permissions.*' => ['string', Rule::in(array_keys(Permissions::all()))],
        ]);

        if (array_key_exists('description', $validated)) {
            $role->update(['description' => $validated['description']]);
        }

        $role->permissions()->sync($this->idsFor($validated['permissions']));

        return response()->json([
            'role' => $this->present($role->fresh(['permissions'])),
            'message' => 'Permissions updated for '.$role->name.'.',
        ]);
    }

    /**
     * Permission rows for the given names, creating any the seeder has not been
     * re-run for yet. The names are validated against the vocabulary above, so
     * this cannot invent one.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    private function idsFor(array $names): array
    {
        $descriptions = Permissions::all();

        return array_map(
            fn (string $name): int => (int) Permission::query()->updateOrCreate(
                ['name' => $name],
                ['description' => $descriptions[$name]],
            )->getKey(),
            array_values(array_unique($names)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Role $role): array
    {
        return [
            'id' => $role->getKey(),
            'name' => $role->name,
            'description' => $role->description,
            'permissions' => $role->permissions->pluck('name')->values()->all(),
        ];
    }
}
