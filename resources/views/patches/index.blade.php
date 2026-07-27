<x-layouts.app title="Patches">
    <x-card title="Patch registry"
            subtitle="Target repository and directory live on the patch, so an operator never retypes the target path at deploy time.">
        <x-slot:actions>
            @can('create', \App\Models\Patch::class)
                <a href="{{ route('patches.create') }}"
                   class="rounded-md bg-slate-900 px-3 py-1.5 font-medium text-white hover:bg-slate-700">
                    Add patch
                </a>
            @endcan
        </x-slot:actions>

        @if ($patches->isEmpty())
            <p class="text-sm text-slate-500">No patches registered.</p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($patches as $patch)
                    <li class="py-4 first:pt-0 last:pb-0 {{ $patch->active ? '' : 'opacity-60' }}">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <span class="font-medium">{{ $patch->name }}</span>

                            @unless ($patch->active)
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">inactive</span>
                            @endunless

                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">
                                {{ $patch->format === 'git' ? 'git apply' : 'patch -p1' }}
                            </span>

                            @if ($patch->last_check_ok === true)
                                <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-800">
                                    applied cleanly {{ $patch->last_checked_at?->diffForHumans() }}
                                </span>
                            @elseif ($patch->last_check_ok === false)
                                <span class="rounded bg-rose-100 px-1.5 py-0.5 text-xs text-rose-800">
                                    failed dry run {{ $patch->last_checked_at?->diffForHumans() }}
                                </span>
                            @endif

                            <div class="ml-auto flex items-center gap-2 text-sm">
                                @can('check', $patch)
                                    <form method="POST" action="{{ route('patches.check', $patch) }}">
                                        @csrf
                                        <button type="submit" class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50">
                                            Dry run
                                        </button>
                                    </form>
                                @endcan
                                @can('update', $patch)
                                    <a href="{{ route('patches.edit', $patch) }}" class="text-slate-500 hover:text-slate-900">Edit</a>
                                @endcan
                            </div>
                        </div>

                        <dl class="mt-1 grid gap-x-6 gap-y-1 text-xs text-slate-500 sm:grid-cols-2">
                            <div>
                                <dt class="inline uppercase tracking-wide">Target</dt>
                                <dd class="inline">
                                    {{ $patch->targetRepository?->displayName() ?? 'freeform (no repository)' }}
                                    <code class="ml-1 font-mono">{{ $patch->target_path }}</code>
                                </dd>
                            </div>
                            <div>
                                <dt class="inline uppercase tracking-wide">File</dt>
                                <dd class="inline"><code class="font-mono">{{ $patch->shimPatchPath() }}</code></dd>
                            </div>
                        </dl>

                        @if ($patch->description)
                            <p class="mt-1 text-sm text-slate-600">{{ $patch->description }}</p>
                        @endif

                        @if ($patch->last_check_ok === false && $patch->last_check_detail)
                            <pre class="mt-2 max-h-32 overflow-auto rounded bg-rose-50 px-2 py-1 font-mono text-xs whitespace-pre-wrap text-rose-900">{{ $patch->last_check_detail }}</pre>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
</x-layouts.app>
