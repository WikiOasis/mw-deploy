<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Apps\AppRegistry;
use App\Apps\ConsoleApp;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The console's central access management: accounts, roles, and which of each
 * app's permissions a role grants.
 *
 * This is the one screen that is not an app — it is how apps are handed out, so
 * it cannot itself be behind an app grant. There is no self-registration: this
 * console can push code to every production appserver, so accounts are created
 * by someone holding users.manage.
 */
final class UserController extends Controller
{
    public function index(AppRegistry $registry): JsonResponse
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
                    // Which apps this role opens, so the screen can say what a
                    // role is *for* before anyone reads its permission list.
                    'apps' => array_values(array_map(
                        fn (ConsoleApp $app): string => $app->id(),
                        array_filter(
                            $registry->enabled(),
                            fn (ConsoleApp $app): bool => array_intersect(
                                array_keys($app->permissions()),
                                $role->permissions->pluck('name')->all(),
                            ) !== [],
                        ),
                    )),
                ])->all(),
            /*
             * The permission vocabulary, grouped the way it is granted: one
             * section per app, plus the console's own. Each section is complete
             * whether or not the seeder has been re-run, because it comes from
             * the code rather than from the permissions table.
             */
            'permission_groups' => $this->permissionGroups($registry),
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

    /**
     * One section per app, in launcher order, with the console's own permissions
     * first — they are the ones that hand the apps out.
     *
     * @return list<array<string, mixed>>
     */
    private function permissionGroups(AppRegistry $registry): array
    {
        $section = static fn (string $key, string $label, string $summary, array $permissions): array => [
            'key' => $key,
            'label' => $label,
            'summary' => $summary,
            'permissions' => array_map(
                static fn (string $name, string $description): array => [
                    'name' => $name,
                    'description' => $description,
                    'grants_access' => Permissions::isAccessPermission($name),
                ],
                array_keys($permissions),
                array_values($permissions),
            ),
        ];

        $groups = [$section(
            Permissions::CONSOLE,
            'Console',
            'Accounts, roles and the grants themselves. Not an app: this is how the apps are handed out.',
            Permissions::forApp(Permissions::CONSOLE),
        )];

        foreach ($registry->enabled() as $app) {
            $groups[] = $section($app->id(), $app->name(), $app->summary(), $app->permissions());
        }

        return $groups;
    }
}
