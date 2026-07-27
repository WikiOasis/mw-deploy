<x-layouts.app title="Repositories">
    <x-card title="Repositories" subtitle="Core versions, extensions, skins and config known to the portal.">
        <x-slot:actions>
            @can('create', \App\Models\Repository::class)
                <a href="{{ route('repositories.create') }}"
                   class="rounded-md bg-slate-900 px-3 py-1.5 font-medium text-white hover:bg-slate-700">
                    Register repository
                </a>
            @endcan
        </x-slot:actions>

        <form method="GET" class="mb-5 flex flex-wrap items-end gap-3">
            <div class="w-64">
                <x-field label="Search" name="q">
                    <x-input name="q" value="{{ $search }}" placeholder="Echo, Vector, mw-config…" />
                </x-field>
            </div>

            <div class="w-48">
                <x-field label="Type" name="type">
                    <select name="type" class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">
                        <option value="">All types</option>
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}" @selected($selectedType === $type)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>

            <label class="flex items-center gap-2 pb-2 text-sm text-slate-600">
                <input type="checkbox" name="in_use" value="1" @checked($inUseOnly) class="rounded border-slate-300">
                Only repos the farm enables
            </label>

            <button type="submit" class="mb-0.5 rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
                Filter
            </button>
        </form>

        @if ($repositories->isEmpty())
            <p class="text-sm text-slate-500">No repositories match. Register one to get started.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="py-2 pr-4">Name</th>
                            <th class="py-2 pr-4">Type</th>
                            <th class="py-2 pr-4">Version</th>
                            <th class="py-2 pr-4">Default branch</th>
                            <th class="py-2 pr-4">Staging path</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($repositories as $repository)
                            <tr class="{{ $repository->active ? '' : 'text-slate-400' }}">
                                <td class="py-2 pr-4">
                                    <a href="{{ route('repositories.show', $repository) }}" class="font-medium hover:underline">
                                        {{ $repository->name }}
                                    </a>
                                    @unless ($repository->active)
                                        <span class="ml-1 text-xs">(inactive)</span>
                                    @endunless
                                    @if ($repository->in_use)
                                        <span class="ml-1 rounded bg-sky-100 px-1.5 py-0.5 text-xs text-sky-800">in use</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $repository->type->label() }}</td>
                                <td class="py-2 pr-4">{{ $repository->core_version ?? '—' }}</td>
                                <td class="py-2 pr-4 font-mono text-xs">{{ $repository->default_branch }}</td>
                                <td class="py-2 pr-4 font-mono text-xs text-slate-500">{{ $repository->path }}</td>
                                <td class="py-2 text-right">
                                    @can('update', $repository)
                                        <a href="{{ route('repositories.edit', $repository) }}" class="text-slate-500 hover:text-slate-900">Edit</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $repositories->links() }}</div>
        @endif
    </x-card>
</x-layouts.app>
