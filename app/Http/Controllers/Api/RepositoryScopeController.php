<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Models\RepositoryPermission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Per-repository scoping: the deployments app's own narrowing of who may act on
 * which repository, on top of the coarse deploy.<type> grants a role carries.
 *
 * A repository with no rows here is governed purely by deploy.<type>; adding the
 * first row narrows it to the listed users and roles.
 *
 * It lives inside the deployments app rather than with the console's accounts
 * because it is about repositories — but it is still access administration, so
 * it wants users.manage as well as access to this app.
 */
final class RepositoryScopeController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        return response()->json([
            'scopes' => RepositoryPermission::query()
                ->with(['repository', 'user', 'role'])
                ->get()
                ->map(fn (RepositoryPermission $scope): array => [
                    'id' => $scope->getKey(),
                    'repository_id' => $scope->repository_id,
                    'repository_name' => $scope->repository?->name,
                    'user_id' => $scope->user_id,
                    'user_name' => $scope->user?->name,
                    'role_id' => $scope->role_id,
                    'role_name' => $scope->role?->name,
                ])->all(),
            'repositories' => Repository::query()->active()->orderBy('type')->orderBy('name')->get()
                ->map(fn (Repository $repository): array => [
                    'id' => $repository->getKey(),
                    'name' => $repository->name,
                    'type' => $repository->type->value,
                ])->all(),
            'users' => User::query()->orderBy('name')->get()
                ->map(fn (User $user): array => [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                ])->all(),
            'roles' => Role::query()->orderBy('name')->get()
                ->map(fn (Role $role): array => [
                    'id' => $role->getKey(),
                    'name' => $role->name,
                ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $validated = $request->validate([
            'repository_id' => ['required', 'integer', Rule::exists('repositories', 'id')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
        ]);

        if (($validated['user_id'] ?? null) === null && ($validated['role_id'] ?? null) === null) {
            throw ValidationException::withMessages([
                'user_id' => 'Choose a user or a role to scope this repository to.',
            ]);
        }

        RepositoryPermission::query()->updateOrCreate([
            'repository_id' => $validated['repository_id'],
            'user_id' => $validated['user_id'] ?? null,
            'role_id' => $validated['role_id'] ?? null,
        ]);

        return response()->json(['message' => 'Repository scoping updated.']);
    }

    public function destroy(RepositoryPermission $repositoryPermission): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $repositoryPermission->delete();

        return response()->json(['message' => 'Repository scoping removed.']);
    }
}
