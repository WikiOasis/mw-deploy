<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Repository;
use App\Models\RepositoryPermission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Account and role administration. There is no self-registration: this portal
 * can push code to every production appserver, so accounts are created by
 * someone holding users.manage.
 */
final class UserController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        return view('users.index', [
            'users' => User::query()->with('roles')->orderBy('name')->get(),
            'roles' => Role::query()->with('permissions')->orderBy('name')->get(),
            'permissions' => Permission::query()->orderBy('name')->get(),
            'repositories' => Repository::query()->active()->orderBy('type')->orderBy('name')->get(),
            'repositoryPermissions' => RepositoryPermission::query()->with(['repository', 'user', 'role'])->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
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

        return back()->with('status', $user->email.' created. They must enrol TOTP before deploying.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manageRoles', $user);

        $validated = $request->validate([
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['integer', Rule::exists('roles', 'id')],
        ]);

        $user->roles()->sync($validated['roles'] ?? []);
        $user->forgetPermissionCache();

        return back()->with('status', 'Roles updated for '.$user->email.'.');
    }

    /**
     * Per-repository scoping from section 3.5.2. A repository with no rows here
     * is governed purely by its coarse deploy.<type> permission; adding the first
     * row narrows it to the listed users and roles.
     */
    public function scopeRepository(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', User::class);

        $validated = $request->validate([
            'repository_id' => ['required', 'integer', Rule::exists('repositories', 'id')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
        ]);

        if (($validated['user_id'] ?? null) === null && ($validated['role_id'] ?? null) === null) {
            return back()->withErrors(['user_id' => 'Choose a user or a role to scope this repository to.']);
        }

        RepositoryPermission::query()->updateOrCreate([
            'repository_id' => $validated['repository_id'],
            'user_id' => $validated['user_id'] ?? null,
            'role_id' => $validated['role_id'] ?? null,
        ]);

        return back()->with('status', 'Repository scoping updated.');
    }

    public function unscopeRepository(RepositoryPermission $repositoryPermission): RedirectResponse
    {
        $this->authorize('viewAny', User::class);

        $repositoryPermission->delete();

        return back()->with('status', 'Repository scoping removed.');
    }
}
