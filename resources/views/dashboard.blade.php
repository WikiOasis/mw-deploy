<x-layouts.app title="Dashboard">
    <div class="mb-8 grid gap-4 sm:grid-cols-3">
        <x-card>
            <p class="text-sm text-slate-500">Active repositories</p>
            <p class="mt-1 text-2xl font-semibold">{{ $repositoryCount }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">Appservers</p>
            <p class="mt-1 text-2xl font-semibold">{{ $appserverCount }}</p>
        </x-card>
        <x-card>
            <p class="text-sm text-slate-500">Proxies</p>
            <p class="mt-1 text-2xl font-semibold">{{ $proxyCount }}</p>
        </x-card>
    </div>

    @if ($active->isNotEmpty())
        <x-card title="In flight" subtitle="Staging is a single working tree, so deployments run one at a time." class="mb-8">
            <ul class="divide-y divide-slate-100">
                @foreach ($active as $deployment)
                    <li class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                        <x-status-badge :status="$deployment->status" />
                        <a href="{{ route('deployments.show', $deployment) }}" class="font-medium hover:underline">
                            #{{ $deployment->id }}
                        </a>
                        <span class="text-sm text-slate-500">
                            {{ Str::limit($deployment->summary(), 90) }}
                        </span>
                        @if ($deployment->awaitingDecision())
                            <span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">
                                waiting on a decision
                            </span>
                        @endif
                        <span class="ml-auto text-sm text-slate-400">{{ $deployment->creator?->name ?? 'system' }}</span>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <x-card title="Recent deployments">
        <x-slot:actions>
            <a href="{{ route('deployments.index') }}" class="text-slate-600 hover:text-slate-900">View all</a>
        </x-slot:actions>

        @if ($recent->isEmpty())
            <p class="text-sm text-slate-500">Nothing has been deployed through the portal yet.</p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($recent as $deployment)
                    <li class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                        <x-status-badge :status="$deployment->status" />
                        <a href="{{ route('deployments.show', $deployment) }}" class="font-medium hover:underline">
                            #{{ $deployment->id }}
                        </a>
                        @if ($deployment->isRollback())
                            <span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">
                                Rollback of #{{ $deployment->rolls_back_deployment_id }}
                            </span>
                        @else
                            <span class="text-sm text-slate-500">
                                {{ Str::limit($deployment->summary(), 90) }}
                            </span>
                        @endif
                        <span class="ml-auto text-sm text-slate-400">
                            {{ $deployment->creator?->name ?? 'system' }} ·
                            {{ $deployment->finished_at?->diffForHumans() ?? '—' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>
</x-layouts.app>
