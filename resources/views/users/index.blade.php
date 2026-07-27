<x-layouts.app title="Users and roles">
    <div class="space-y-6">
        <x-card title="Accounts"
                subtitle="Any account holding a deploy permission must enrol TOTP before it can use the portal.">
            <ul class="divide-y divide-slate-100">
                @foreach ($users as $user)
                    <li class="py-4 first:pt-0 last:pb-0">
                        <form method="POST" action="{{ route('users.update', $user) }}" class="flex flex-wrap items-start gap-4">
                            @csrf
                            @method('PUT')

                            <div class="min-w-56">
                                <p class="font-medium">{{ $user->name }}</p>
                                <p class="text-xs text-slate-500">{{ $user->email }}</p>
                                @if ($user->hasTwoFactorEnabled())
                                    <span class="mt-1 inline-block rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-800">2FA on</span>
                                @elseif ($user->requiresTwoFactor())
                                    <span class="mt-1 inline-block rounded bg-rose-100 px-1.5 py-0.5 text-xs text-rose-800">2FA required, not enrolled</span>
                                @else
                                    <span class="mt-1 inline-block rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">read-only</span>
                                @endif
                            </div>

                            <div class="flex flex-wrap gap-x-4 gap-y-1">
                                @foreach ($roles as $role)
                                    <label class="flex items-center gap-2 text-sm" title="{{ $role->description }}">
                                        <input type="checkbox" name="roles[]" value="{{ $role->id }}"
                                               @checked($user->roles->contains($role))
                                               class="rounded border-slate-300">
                                        {{ $role->name }}
                                    </label>
                                @endforeach
                            </div>

                            <button type="submit" class="ml-auto rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50">
                                Save roles
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </x-card>

        <x-card title="Create an account" subtitle="There is no self-registration.">
            <form method="POST" action="{{ route('users.store') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf

                <x-field label="Name" name="name" required>
                    <x-input name="name" value="{{ old('name') }}" required />
                </x-field>

                <x-field label="Email" name="email" required>
                    <x-input type="email" name="email" value="{{ old('email') }}" required />
                </x-field>

                <x-field label="Initial password" name="password" hint="At least 12 characters." required>
                    <x-input type="password" name="password" required autocomplete="new-password" />
                </x-field>

                <x-field label="Roles" name="roles">
                    <div class="flex flex-wrap gap-x-4 gap-y-1 pt-1">
                        @foreach ($roles as $role)
                            <label class="flex items-center gap-2 text-sm" title="{{ $role->description }}">
                                <input type="checkbox" name="roles[]" value="{{ $role->id }}" class="rounded border-slate-300">
                                {{ $role->name }}
                            </label>
                        @endforeach
                    </div>
                </x-field>

                <div class="sm:col-span-2">
                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                        Create account
                    </button>
                </div>
            </form>
        </x-card>

        <x-card title="Roles and permissions">
            <ul class="divide-y divide-slate-100">
                @foreach ($roles as $role)
                    <li class="py-3 first:pt-0 last:pb-0">
                        <p class="font-medium">{{ $role->name }}</p>
                        <p class="text-xs text-slate-500">{{ $role->description }}</p>
                        <div class="mt-1 flex flex-wrap gap-1">
                            @forelse ($role->permissions as $permission)
                                <code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs">{{ $permission->name }}</code>
                            @empty
                                <span class="text-xs text-slate-500">Read-only — no permissions granted.</span>
                            @endforelse
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-card>

        <x-card title="Per-repository scoping"
                subtitle="Optional. A repository with no rows here is governed purely by its deploy.<type> permission; adding the first row narrows it to those users and roles.">
            @if ($repositoryPermissions->isEmpty())
                <p class="text-sm text-slate-500">
                    Nothing scoped — coarse per-type permissions apply to every repository.
                </p>
            @else
                <ul class="mb-5 divide-y divide-slate-100 text-sm">
                    @foreach ($repositoryPermissions as $scope)
                        <li class="flex items-center gap-2 py-2 first:pt-0">
                            <span class="font-medium">{{ $scope->repository?->displayName() ?? 'deleted repository' }}</span>
                            <span class="text-slate-500">→</span>
                            <span>{{ $scope->user?->email ?? 'role: '.($scope->role?->name ?? '—') }}</span>

                            <form method="POST" action="{{ route('users.unscope', $scope) }}" class="ml-auto">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs text-rose-600 hover:underline">Remove</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('users.scope') }}" class="grid gap-4 sm:grid-cols-3">
                @csrf

                <x-field label="Repository" name="repository_id" required>
                    <select name="repository_id" required class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">
                        @foreach ($repositories as $repository)
                            <option value="{{ $repository->id }}">{{ $repository->displayName() }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="User" name="user_id" hint="Or leave blank and pick a role.">
                    <select name="user_id" class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">
                        <option value="">—</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}">{{ $user->email }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="Role" name="role_id">
                    <select name="role_id" class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">
                        <option value="">—</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </select>
                </x-field>

                <div class="sm:col-span-3">
                    <button type="submit" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                        Add scoping
                    </button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
