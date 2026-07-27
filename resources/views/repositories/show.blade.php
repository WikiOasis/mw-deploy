<x-layouts.app :title="$repository->name">
    <div class="space-y-6">
        <x-card :title="$repository->name" :subtitle="$repository->type->label()">
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
            </dl>
        </x-card>

        <x-card title="Checkouts"
                subtitle="One per core version. Each keeps its own pinned ref, so a deploy across all versions sends the right branch to each.">
            @if ($checkouts->isEmpty())
                <p class="text-sm text-slate-500">
                    Not checked out anywhere yet. Deploy it into a version from the wizard.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="py-2 pr-4">Version</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Ref</th>
                                <th class="py-2 pr-4">Staging path</th>
                                <th class="py-2 pr-4">Patches</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($checkouts as $checkout)
                                <tr>
                                    <td class="py-2 pr-4">
                                        @if ($checkout->mediawikiVersion)
                                            <a href="{{ route('versions.show', $checkout->mediawikiVersion) }}" class="font-medium hover:underline">
                                                {{ $checkout->versionLabel() }}
                                            </a>
                                        @else
                                            <span class="font-medium">{{ $checkout->versionLabel() }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $checkout->status->badgeClasses() }}">
                                            {{ $checkout->status->label() }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4 text-slate-600">{{ $checkout->refModeSummary() }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs text-slate-500 break-all">{{ $checkout->path }}</td>
                                    <td class="py-2 pr-4 text-slate-500">
                                        {{ $checkout->patches->count() ?: '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

        @unless ($discoveryAvailable)
            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Ref discovery is disabled (<code>MWDEPLOY_GIT_DRIVER=none</code>), so the ref picker only offers free
                text and each checkout's pin.
            </div>
        @endunless

        @if ($readableCheckout === null)
            <div class="rounded-md border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
                No checkout of this repository is on disk, so there is no clone to read branches from yet.
            </div>
        @else
            <div class="grid gap-6 lg:grid-cols-2">
                <x-card title="Branches" :subtitle="'read from '.$readableCheckout->displayName()">
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

                <x-card title="Recent commits">
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
        @endif
    </div>
</x-layouts.app>
