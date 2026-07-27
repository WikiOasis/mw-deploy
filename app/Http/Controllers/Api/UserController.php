<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Permission;
use App\Models\Repository;
use App\Models\RepositoryPermission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Account and role administration. There is no self-registration: this portal can
 * push code to every production appserver, so accounts are created by someone
 * holding users.manage.
 */
final class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        return response()->json([
            'users' => UserResource::collection(
                User::query()->with('roles')->orderBy('name')->get()
            )->resolve(),
            'roles' => Role::query()->with('permissions')->orderBy('name')->get()
                ->map(fn (Role $role): array => [
                    'id' => $role->getKey(),
                    'name' => $role->name,
                    'description' => $role->description,
                    'permissions' => $role->permissions->pluck('name')->values()->all(),
                ])->all(),
            'permissions' => Permission::query()->orderBy('name')->get()
                ->map(fn (Permission $permission): array => [
                    'id' => $permission->getKey(),
                    'name' => $permission->name,
                    'description' => $permission->description,
                ])->all(),
            'repositories' => Repository::query()->active()->orderBy('type')->orderBy('name')->get()
                ->map(fn (Repository $repository): array => [
                    'id' => $repository->getKey(),
                    'name' => $repository->name,
                    'type' => $repository->type->value,
                ])->all(),
            // A repository with no rows here is governed purely by its coarse
            // deploy.<type> permission; adding the first row narrows it to the
            // listed users and roles.
            'repository_permissions' => RepositoryPermission::query()
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
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(12)],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['integer', Rule::exists('roles', 'id')],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->roles()->sync($validated['roles'] ?? []);
        $user->load('roles');

        return response()->json([
            'user' => (new UserResource($user))->resolve(),
            'message' => $user->email.' created. They must enrol TOTP before deploying.',
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('manageRoles', $user);

        $validated = $request->validate([
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['integer', Rule::exists('roles', 'id')],
        ]);

        $user->roles()->sync($validated['roles'] ?? []);
        $user->forgetPermissionCache();
        $user->load('roles');

        return response()->json([
            'user' => (new UserResource($user))->resolve(),
            'message' => 'Roles updated for '.$user->email.'.',
        ]);
    }

    public function scopeRepository(Request $request): JsonResponse
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

    public function unscopeRepository(RepositoryPermission $repositoryPermission): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $repositoryPermission->delete();

        return response()->json(['message' => 'Repository scoping removed.']);
    }
}
