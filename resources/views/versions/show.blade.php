<x-layouts.app :title="'MediaWiki '.$version->version">
    <div class="space-y-6">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold tracking-tight">MediaWiki {{ $version->version }}</h1>
            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $version->status->badgeClasses() }}">
                {{ $version->status->label() }}
            </span>
            <code class="font-mono text-sm text-slate-500">{{ $version->relativePath() }}</code>

            @if ($version->createdFrom)
                <span class="text-sm text-slate-500">
                    built from <a href="{{ route('versions.show', $version->createdFrom) }}" class="underline">{{ $version->createdFrom->version }}</a>
                </span>
            @endif
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            @foreach ($types as $type)
                @php $checkouts = $checkoutsByType->get($type->value, collect()); @endphp

                @if ($checkouts->isNotEmpty())
                    <x-card :title="$type->pluralLabel()" :subtitle="$checkouts->count().' checkout(s)'">
                        <ul class="divide-y divide-slate-100 text-sm">
                            @foreach ($checkouts as $checkout)
                                <li class="flex flex-wrap items-baseline gap-2 py-2 first:pt-0">
                                    <a href="{{ route('repositories.show', $checkout->repository) }}" class="font-medium hover:underline">
                                        {{ $checkout->repository?->name }}
                                    </a>
                                    <span class="rounded px-1.5 py-0.5 text-xs ring-1 ring-inset {{ $checkout->status->badgeClasses() }}">
                                        {{ $checkout->status->label() }}
                                    </span>
                                    <span class="ml-auto text-xs text-slate-500">{{ $checkout->refModeSummary() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </x-card>
                @endif
            @endforeach
        </div>

        @if ($version->deployments->isNotEmpty())
            <x-card title="Deployments for this version">
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($version->deployments->sortByDesc('id') as $deployment)
                        <li class="flex flex-wrap items-center gap-3 py-2 first:pt-0">
                            <x-status-badge :status="$deployment->status" />
                            <a href="{{ route('deployments.show', $deployment) }}" class="font-medium hover:underline">
                                #{{ $deployment->getKey() }}
                            </a>
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $deployment->intent->badgeClasses() }}">
                                {{ $deployment->intent->label() }}
                            </span>
                            <span class="ml-auto text-xs text-slate-500">
                                {{ $deployment->creator?->name ?? 'system' }} ·
                                {{ $deployment->finished_at?->diffForHumans() ?? 'in flight' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        @can('undeploy', $version)
            <x-card title="Undeploy this version">
                <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                    <p class="font-medium">
                        This deletes <code class="font-mono">{{ $version->relativePath() }}</code> from staging and
                        from every targeted appserver.
                    </p>
                    <p class="mt-1">
                        Every wiki still pointed at {{ $version->version }} will break. The deployment refuses to run
                        if the farm's wiki-version map still lists it, and refuses if that map cannot be read at all
                        — but move your wikis first rather than relying on that.
                    </p>
                    <p class="mt-1">
                        It is recoverable: an undo point is recorded for all
                        {{ $version->checkouts->count() }} checkout(s), so rolling the deployment back rebuilds the
                        version at the refs it was on.
                    </p>
                </div>

                <form method="POST" action="{{ route('versions.undeploy', $version) }}" class="mt-4 space-y-4">
                    @csrf

                    <div class="max-w-xs">
                        <x-field label="Type the version to confirm" name="confirm_version" required
                                 :hint="'Type '.$version->version.' exactly.'">
                            <x-input name="confirm_version" required autocomplete="off" class="font-mono" />
                        </x-field>
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="rollout" value="1" class="rounded border-slate-300">
                        Depool each server before removing, and repool afterwards
                    </label>

                    <button type="submit" class="rounded-md bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-500">
                        Undeploy {{ $version->version }}
                    </button>
                </form>
            </x-card>
        @endcan
    </div>
</x-layouts.app>
