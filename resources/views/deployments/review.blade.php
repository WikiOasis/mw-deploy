@php
    $isUndeploy = $intent->isRemoval();
@endphp

<x-layouts.app title="Review deployment">
    <div class="space-y-6">
        <div class="rounded-md border px-4 py-3 text-sm {{ $isUndeploy ? 'border-orange-200 bg-orange-50 text-orange-900' : 'border-amber-200 bg-amber-50 text-amber-900' }}">
            <p class="font-medium">Nothing has run yet.</p>
            <p class="mt-1">
                Below is the exact sequence of Salt calls this deployment will make, in order. Confirming starts it.
                @if ($isUndeploy)
                    Every <code class="font-mono">repo-remove</code> below deletes a directory — read the paths.
                @endif
            </p>
        </div>

        <x-card :title="$isUndeploy ? 'What will be removed' : 'What will be deployed'">
            <x-slot:actions>
                <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $intent->badgeClasses() }}">
                    {{ $intent->label() }}
                </span>
            </x-slot:actions>

            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Checkouts</dt>
                    <dd class="mt-1">
                        <ul class="space-y-1">
                            @foreach ($refs as $ref)
                                <li>
                                    <span class="font-medium">{{ $ref->repositoryVersion->displayName() }}</span>
                                    @if ($ref->isUndeploy())
                                        <span class="ml-1 rounded bg-orange-100 px-1.5 py-0.5 text-xs text-orange-900">removed</span>
                                        <code class="ml-2 font-mono text-xs text-slate-500">{{ $ref->repositoryVersion->path }}</code>
                                    @else
                                        <span class="text-slate-500">→</span>
                                        <code class="font-mono text-xs">{{ $ref->ref_value }}</code>
                                        <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs">{{ $ref->ref_type?->value }}</span>
                                        @unless ($ref->repositoryVersion->isPresent())
                                            <span class="ml-1 rounded bg-violet-100 px-1.5 py-0.5 text-xs text-violet-900">will be cloned</span>
                                        @endunless
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </dd>
                </div>

                @unless ($isUndeploy)
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Patches</dt>
                        <dd class="mt-1">
                            @if ($patches->isEmpty())
                                <span class="text-slate-500">None selected.</span>
                            @else
                                <ul class="space-y-1">
                                    @foreach ($patches as $patch)
                                        <li>
                                            {{ $patch->name }}
                                            <code class="ml-1 font-mono text-xs text-slate-500">{{ $patch->target_path }}</code>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </dd>
                    </div>
                @endunless

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Options</dt>
                    <dd class="mt-1 flex flex-wrap gap-1">
                        @foreach ($options->summaryFlags() as $flag)
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $flag }}</span>
                        @endforeach
                    </dd>
                </div>
            </dl>

            @if ($unselectedPatches->isNotEmpty())
                <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <p class="font-medium">
                        {{ $unselectedPatches->count() }} active
                        {{ Str::plural('patch', $unselectedPatches->count()) }}
                        for these checkouts {{ $unselectedPatches->count() === 1 ? 'is' : 'are' }} not selected:
                    </p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5">
                        @foreach ($unselectedPatches as $patch)
                            <li>{{ $patch->name }} <code class="font-mono text-xs">{{ $patch->target_path }}</code></li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs">
                        Go back if that was not deliberate — an unapplied patch silently un-patches the farm.
                    </p>
                </div>
            @endif

            @if ($options->force)
                <div class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                    <p class="font-medium">--force is set.</p>
                    <p class="mt-1">
                        Canary failures will not stop this deployment, will not prompt you, and will not trigger an
                        automatic rollback.
                    </p>
                </div>
            @endif
        </x-card>

        <x-card :title="'Salt calls — '.collect($planned)->flatten(1)->count().' in total'"
                subtitle="One call per step per server. The portal sequences these itself; Salt is only the transport.">
            <div class="space-y-5">
                @foreach ($planned as $phase => $calls)
                    <section>
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $phase }}</h3>
                        <ol class="space-y-1">
                            @foreach ($calls as $call)
                                @php $isRemoval = str_contains($call->commandLine(), 'repo-remove'); @endphp
                                <li class="rounded border px-3 py-2 {{ $isRemoval ? 'border-orange-200 bg-orange-50' : 'border-slate-200 bg-slate-50' }}">
                                    <p class="text-sm font-medium">{{ $call->label() }}
                                        <span class="ml-1 font-normal text-slate-500">on {{ $call->target() }}</span>
                                    </p>
                                    <code class="mt-0.5 block overflow-x-auto font-mono text-xs whitespace-pre text-slate-600">{{ $call->commandLine() }}</code>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endforeach
            </div>
        </x-card>

        <form method="POST" action="{{ route('deployments.store') }}" class="flex items-center gap-3"
              @if ($isUndeploy) onsubmit="return confirm('Remove {{ $refs->count() }} checkout(s) from staging and every targeted server?')" @endif>
            @csrf

            {{-- The reviewed payload, resubmitted verbatim so what was confirmed is
                 what runs. --}}
            <input type="hidden" name="intent" value="{{ $payload['intent'] }}">

            @foreach ($payload['items'] as $index => $item)
                <input type="hidden" name="items[{{ $index }}][repository_version_id]" value="{{ $item['repository_version_id'] }}">
                @if ($item['ref_value'] !== null)
                    <input type="hidden" name="items[{{ $index }}][ref_type]" value="{{ $item['ref_type'] }}">
                    <input type="hidden" name="items[{{ $index }}][ref_value]" value="{{ $item['ref_value'] }}">
                @endif
            @endforeach

            @foreach ($payload['patches'] as $patchId)
                <input type="hidden" name="patches[]" value="{{ $patchId }}">
            @endforeach

            @foreach ($payload['servers'] as $hostname)
                <input type="hidden" name="servers[]" value="{{ $hostname }}">
            @endforeach

            <input type="hidden" name="parallel" value="{{ $payload['parallel'] }}">
            @if ($payload['force'])<input type="hidden" name="force" value="1">@endif
            @if ($payload['l10n'])<input type="hidden" name="l10n" value="1">@endif
            @if ($payload['rollout'])<input type="hidden" name="rollout" value="1">@endif
            @if ($payload['staging_only'])<input type="hidden" name="staging_only" value="1">@endif

            <button type="submit"
                    class="rounded-md px-4 py-2 text-sm font-medium text-white {{ $isUndeploy ? 'bg-orange-600 hover:bg-orange-500' : 'bg-slate-900 hover:bg-slate-700' }}">
                {{ $isUndeploy ? 'Remove them' : 'Start deployment' }}
            </button>
            <a href="{{ $isUndeploy ? route('deployments.undeploy') : route('deployments.create') }}"
               class="text-sm text-slate-500 hover:text-slate-900">Back to the wizard</a>
        </form>
    </div>
</x-layouts.app>
