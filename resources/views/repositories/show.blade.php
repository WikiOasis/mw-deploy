<x-layouts.app :title="$repository->displayName()">
    <div class="space-y-6">
        <x-card :title="$repository->displayName()" :subtitle="$repository->type->label()">
            <x-slot:actions>
                @can('update', $repository)
                    <a href="{{ route('repositories.edit', $repository) }}" class="text-slate-600 hover:text-slate-900">Edit</a>
                @endcan
            </x-slot:actions>

            <dl class="grid gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Remote</dt>
                    <dd class="mt-0.5 font-mono text-sm break-all">{{ $repository->git_url }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Default branch</dt>
                    <dd class="mt-0.5 font-mono text-sm">{{ $repository->default_branch }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Staging path</dt>
                    <dd class="mt-0.5 font-mono text-sm break-all">{{ $repository->stagingPath() }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Production path</dt>
                    <dd class="mt-0.5 font-mono text-sm break-all">{{ $repository->productionPath() }}</dd>
                </div>
            </dl>
        </x-card>

        @unless ($discoveryAvailable)
            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Ref discovery is disabled (<code>MWDEPLOY_GIT_DRIVER=none</code>), so the ref picker only offers free
                text and each repo's default branch.
            </div>
        @endunless

        <div class="grid gap-6 lg:grid-cols-2">
            <x-card title="Branches" :subtitle="count($branches).' remote-tracking branches'">
                @if ($branches === [])
                    <p class="text-sm text-slate-500">No branches found in the staging clone.</p>
                @else
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($branches as $branch)
                            <li class="flex items-baseline gap-2 py-2 first:pt-0">
                                <code class="font-mono">{{ $branch->value }}</code>
                                @if ($branch->isDefault)
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">default</span>
                                @endif
                                @if ($branch->subject)
                                    <span class="ml-auto truncate text-xs text-slate-500">{{ $branch->subject }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card title="Recent commits" :subtitle="'on '.$repository->default_branch">
                @if ($commits === [])
                    <p class="text-sm text-slate-500">No commits found in the staging clone.</p>
                @else
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($commits as $commit)
                            <li class="py-2 first:pt-0">
                                <code class="font-mono text-xs">{{ $commit->short() }}</code>
                                <span class="ml-2">{{ $commit->subject }}</span>
                                <span class="block text-xs text-slate-500">{{ $commit->author }} · {{ $commit->date }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>

        <x-card title="Patches targeting this repository">
            @if ($patches->isEmpty())
                <p class="text-sm text-slate-500">None registered.</p>
            @else
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($patches as $patch)
                        <li class="flex flex-wrap items-baseline gap-2 py-2 first:pt-0">
                            <span class="font-medium">{{ $patch->name }}</span>
                            <code class="font-mono text-xs text-slate-500">{{ $patch->target_path }}</code>
                            @unless ($patch->active)
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">inactive</span>
                            @endunless
                            @if ($patch->last_check_ok === false)
                                <span class="rounded bg-rose-100 px-1.5 py-0.5 text-xs text-rose-800">last check failed</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>
</x-layouts.app>
