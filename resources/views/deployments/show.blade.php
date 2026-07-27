<x-layouts.app :title="'Deployment #'.$deployment->id">
    <div x-data="deploymentMonitor({
             deploymentId: {{ $deployment->id }},
             stateUrl: '{{ route('deployments.state', $deployment) }}',
             terminal: {{ Js::from($deployment->status->isTerminal()) }},
         })"
         class="space-y-6">

        {{-- Header --}}
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold tracking-tight">Deployment #{{ $deployment->id }}</h1>
            <x-status-badge :status="$deployment->status" />

            @if ($deployment->isRollback())
                <a href="{{ route('deployments.show', $deployment->rolls_back_deployment_id) }}"
                   class="rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 hover:underline">
                    Rollback of #{{ $deployment->rolls_back_deployment_id }}
                </a>
            @endif

            <span class="text-sm text-slate-500">
                by {{ $deployment->creator?->name ?? 'system' }}
                @if ($deployment->started_at)
                    · started {{ $deployment->started_at->diffForHumans() }}
                @endif
                @if ($deployment->durationSeconds() !== null)
                    · {{ $deployment->durationSeconds() }}s
                @endif
            </span>

            <div class="ml-auto flex items-center gap-3 text-sm">
                <span x-show="live" x-cloak class="flex items-center gap-1 text-xs text-emerald-700">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span> live
                </span>

                @can('rollback', $deployment)
                    <form method="POST" action="{{ route('deployments.rollback', $deployment) }}"
                          onsubmit="return confirm('Queue a rollback of deployment #{{ $deployment->id }}?')">
                        @csrf
                        <button type="submit" class="rounded-md border border-amber-300 px-3 py-1.5 font-medium text-amber-900 hover:bg-amber-50">
                            Roll back
                        </button>
                    </form>
                @endcan
            </div>
        </div>

        {{-- Out-of-order rollback warning (section 6.2). --}}
        @if ($newerDeployments->isNotEmpty() && ! $deployment->isRollback())
            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <p class="font-medium">
                    {{ $newerDeployments->count() }} later
                    {{ Str::plural('deployment', $newerDeployments->count()) }}
                    {{ $newerDeployments->count() === 1 ? 'has' : 'have' }} touched the same
                    {{ Str::plural('repository', $deployment->repoRefs->count()) }}:
                    @foreach ($newerDeployments as $newer)
                        <a href="{{ route('deployments.show', $newer) }}" class="underline">#{{ $newer->id }}</a>@if (! $loop->last), @endif
                    @endforeach
                </p>
                <p class="mt-1 text-xs">
                    Rolling this one back out of order will fight with whatever those changed. Prefer rolling back the
                    most recent deployment that touched these repositories.
                </p>
            </div>
        @endif

        {{-- Blocking canary prompt: the web replacement for the curses Prompter. --}}
        <div x-show="awaitingDecision" x-cloak
             class="rounded-lg border-2 border-amber-400 bg-amber-50 p-5">
            <h2 class="font-semibold text-amber-900">
                Canary failed — waiting for a decision
            </h2>
            <p class="mt-1 text-sm text-amber-900">
                <span x-text="pendingContext.host ? 'On ' + pendingContext.host : ''"></span>
                <span x-show="pendingContext.vhost" x-text="' (' + pendingContext.vhost + ')'"></span>
            </p>
            <pre x-show="pendingContext.detail" x-cloak
                 class="mt-2 max-h-40 overflow-auto rounded bg-white/70 p-2 font-mono text-xs whitespace-pre-wrap"
                 x-text="pendingContext.detail"></pre>

            @can('decide', $deployment)
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach (\App\Enums\DeploymentDecision::cases() as $decision)
                        <form method="POST" action="{{ route('deployments.decision', $deployment) }}">
                            @csrf
                            <input type="hidden" name="decision" value="{{ $decision->value }}">
                            <button type="submit"
                                    title="{{ $decision->description() }}"
                                    class="rounded-md px-3 py-1.5 text-sm font-medium
                                        {{ $decision === \App\Enums\DeploymentDecision::Continue
                                            ? 'border border-slate-300 bg-white hover:bg-slate-50'
                                            : 'bg-amber-600 text-white hover:bg-amber-500' }}">
                                {{ $decision->label() }}
                            </button>
                        </form>
                    @endforeach
                </div>
                <p class="mt-2 text-xs text-amber-800">
                    If nobody answers within {{ (int) config('mwdeploy.decisions.timeout') }}s the deployment applies
                    <strong>{{ \App\Enums\DeploymentDecision::from((string) config('mwdeploy.decisions.timeout_default'))->label() }}</strong>
                    rather than leaving the farm parked mid-rollout.
                </p>
            @else
                <p class="mt-3 text-sm text-amber-900">
                    You do not have the <code>deploy.decide</code> permission. Someone who does needs to answer this.
                </p>
            @endcan
        </div>

        @if ($deployment->failure_reason)
            <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                {{ $deployment->failure_reason }}
            </div>
        @endif

        {{-- What is being deployed, and the undo point. --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <x-card title="Refs">
                @if ($deployment->repoRefs->isEmpty())
                    <p class="text-sm text-slate-500">None recorded.</p>
                @else
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($deployment->repoRefs as $ref)
                            <li class="flex flex-wrap items-baseline gap-2 py-2 first:pt-0">
                                <span class="font-medium">{{ $ref->repository?->displayName() ?? 'deleted repository' }}</span>
                                <code class="font-mono text-xs">{{ $ref->ref_value }}</code>
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs">{{ $ref->ref_type->value }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="mt-4 flex flex-wrap gap-1 border-t border-slate-100 pt-3">
                    @foreach ($deployment->opts()->summaryFlags() as $flag)
                        <span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $flag }}</span>
                    @endforeach
                </div>
            </x-card>

            <x-card title="Undo point"
                    subtitle="What staging was at before this deployment changed anything — what a rollback restores.">
                @if ($deployment->snapshots->isEmpty())
                    <p class="text-sm text-slate-500">
                        No snapshots recorded, so this deployment cannot be rolled back automatically.
                    </p>
                @else
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach ($deployment->snapshots as $snapshot)
                            <li class="py-2 first:pt-0">
                                <span class="font-medium">{{ $snapshot->repository?->displayName() ?? 'deleted repository' }}</span>
                                <span class="block text-xs text-slate-500">
                                    was
                                    <code class="font-mono">{{ $snapshot->previous_ref_value ?? 'unknown' }}</code>
                                    →
                                    <code class="font-mono">{{ $snapshot->new_ref_value }}</code>
                                    @unless ($snapshot->isRollbackable())
                                        <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-amber-900">not rollbackable</span>
                                    @endunless
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($deployment->deploymentPatches->isNotEmpty())
                    <div class="mt-4 border-t border-slate-100 pt-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Patches</p>
                        <ul class="mt-1 space-y-1 text-sm">
                            @foreach ($deployment->deploymentPatches as $deploymentPatch)
                                <li>
                                    {{ $deploymentPatch->patch?->name ?? 'deleted patch' }}
                                    @if ($deploymentPatch->applied)
                                        <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-800">applied</span>
                                    @else
                                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">not applied</span>
                                    @endif
                                    @if ($deploymentPatch->applied_to_ref)
                                        <code class="ml-1 font-mono text-xs text-slate-500">against {{ Str::limit($deploymentPatch->applied_to_ref, 12, '') }}</code>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </x-card>
        </div>

        {{-- Per-host step panels: the curses Dashboard, one card per box. --}}
        @foreach ($stepsByHost as $host => $steps)
            @php $isStaging = $host === $stagingHost; @endphp

            <x-card :title="$isStaging ? 'Staging — '.$host : $host"
                    :subtitle="$isStaging ? 'Preparation: checkout, patch, local rsync, canary. Blocks the fleet rollout until it passes.' : null">
                <x-slot:actions>
                    <span class="text-xs text-slate-500"
                          x-text="hostProgress('{{ $host }}').done + '/' + hostProgress('{{ $host }}').total + ' steps'"></span>

                    @if (! $isStaging)
                        @php $target = \App\Models\DeployTarget::query()->where('hostname', $host)->first(); @endphp
                        @if ($target && auth()->user()->can('pool', $deployment))
                            <form method="POST" action="{{ route('targets.pool', $target) }}" class="flex gap-1">
                                @csrf
                                <button type="submit" name="action" value="depool"
                                        class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50">Depool</button>
                                <button type="submit" name="action" value="repool"
                                        class="rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50">Repool</button>
                            </form>
                        @endif
                    @endif
                </x-slot:actions>

                <div class="mb-3 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full bg-slate-900 transition-all"
                         x-bind:style="`width: ${hostProgress('{{ $host }}').percent}%`"></div>
                </div>

                <ul class="divide-y divide-slate-100">
                    @foreach ($steps as $step)
                        <li x-data="{ open: {{ Js::from($step->status === \App\Enums\StepStatus::Failed) }} }" class="py-2 first:pt-0">
                            <button type="button" @click="open = ! open" class="flex w-full items-center gap-2 text-left text-sm">
                                <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold ring-1 ring-inset"
                                      x-bind:class="{
                                          'bg-slate-100 text-slate-600 ring-slate-300': (steps[{{ $step->id }}]?.status ?? '{{ $step->status->value }}') === 'pending',
                                          'bg-sky-100 text-sky-800 ring-sky-300': (steps[{{ $step->id }}]?.status ?? '{{ $step->status->value }}') === 'running',
                                          'bg-emerald-100 text-emerald-800 ring-emerald-300': (steps[{{ $step->id }}]?.status ?? '{{ $step->status->value }}') === 'done',
                                          'bg-rose-100 text-rose-800 ring-rose-300': (steps[{{ $step->id }}]?.status ?? '{{ $step->status->value }}') === 'failed',
                                          'bg-slate-100 text-slate-400 ring-slate-300': (steps[{{ $step->id }}]?.status ?? '{{ $step->status->value }}') === 'skipped',
                                          'bg-amber-100 text-amber-900 ring-amber-300': (steps[{{ $step->id }}]?.status ?? '{{ $step->status->value }}') === 'rolled_back',
                                      }">{{ $step->status->icon() }}</span>

                                <span class="font-medium">{{ $step->label() }}</span>

                                <span class="ml-auto shrink-0 text-xs text-slate-500"
                                      x-text="(steps[{{ $step->id }}]?.elapsed ?? {{ $step->elapsedSeconds() ?? 0 }}) + 's'"></span>
                            </button>

                            <div x-show="open" x-cloak class="mt-2">
                                @if ($step->command)
                                    <code class="block overflow-x-auto rounded bg-slate-50 px-2 py-1 font-mono text-xs whitespace-pre text-slate-600">{{ $step->command }}</code>
                                @endif
                                <pre class="mt-1 max-h-64 overflow-auto rounded bg-slate-900 px-3 py-2 font-mono text-xs whitespace-pre-wrap text-slate-100"
                                     x-text="steps[{{ $step->id }}]?.log ?? {{ Js::from($step->log ?? '(no output yet)') }}"></pre>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endforeach

        @if ($stepsByHost->isEmpty())
            <x-card>
                <p class="text-sm text-slate-500">
                    Queued. The worker has not started this deployment yet — make sure a queue worker is running.
                </p>
            </x-card>
        @endif
    </div>
</x-layouts.app>
