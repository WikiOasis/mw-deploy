<x-layouts.app :title="'Edit '.$repository->displayName()">
    <div class="mx-auto max-w-2xl space-y-6">
        <x-card :title="'Edit '.$repository->displayName()"
                subtitle="Metadata only. The staging path is fixed at registration — moving a live checkout is a filesystem operation, not a form field.">
            <form method="POST" action="{{ route('repositories.update', $repository) }}" class="space-y-4">
                @csrf
                @method('PUT')

                {{-- Immutable, but required by the shared validation rules. --}}
                <input type="hidden" name="name" value="{{ $repository->name }}">
                <input type="hidden" name="type" value="{{ $repository->type->value }}">
                <input type="hidden" name="core_version" value="{{ $repository->core_version }}">

                <x-field label="Git remote" name="git_url" required>
                    <x-input name="git_url" value="{{ old('git_url', $repository->git_url) }}" required />
                </x-field>

                <x-field label="Default branch" name="default_branch" required>
                    <x-input name="default_branch" value="{{ old('default_branch', $repository->default_branch) }}" required />
                </x-field>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="in_use" value="1" @checked(old('in_use', $repository->in_use))
                           class="rounded border-slate-300">
                    The farm actually enables this (per mw-config)
                </label>

                <dl class="grid gap-3 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-xs sm:grid-cols-2">
                    <div>
                        <dt class="uppercase tracking-wide text-slate-500">Staging path</dt>
                        <dd class="mt-0.5 font-mono break-all">{{ $repository->stagingPath() }}</dd>
                    </div>
                    <div>
                        <dt class="uppercase tracking-wide text-slate-500">Registered</dt>
                        <dd class="mt-0.5">{{ $repository->registered_at?->toDayDateTimeString() ?? 'not recorded' }}</dd>
                    </div>
                </dl>

                <div class="flex items-center gap-3">
                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                        Save
                    </button>
                    <a href="{{ route('repositories.show', $repository) }}" class="text-sm text-slate-500 hover:text-slate-900">Cancel</a>
                </div>
            </form>
        </x-card>

        @can('delete', $repository)
            <x-card title="Deactivate">
                <p class="text-sm text-slate-600">
                    Past deployments reference this repository, so it is deactivated rather than deleted. It disappears
                    from the wizard but history keeps resolving.
                </p>

                <form method="POST" action="{{ route('repositories.destroy', $repository) }}" class="mt-3"
                      onsubmit="return confirm('Deactivate {{ $repository->displayName() }}?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-md border border-rose-300 px-3 py-1.5 text-sm text-rose-700 hover:bg-rose-50">
                        Deactivate
                    </button>
                </form>
            </x-card>
        @endcan
    </div>
</x-layouts.app>
