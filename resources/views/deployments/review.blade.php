<x-layouts.app title="Review deployment">
    <div class="space-y-6">
        <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <p class="font-medium">Nothing has run yet.</p>
            <p class="mt-1">
                Below is the exact sequence of Salt calls this deployment will make, in order. Confirming starts it.
            </p>
        </div>

        <x-card title="What will be deployed">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Refs</dt>
                    <dd class="mt-1">
                        <ul class="space-y-1">
                            @foreach ($refs as $ref)
                                <li>
                                    <span class="font-medium">{{ $ref->repository->displayName() }}</span>
                                    <span class="text-slate-500">→</span>
                                    <code class="font-mono text-xs">{{ $ref->ref_value }}</code>
                                    <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs">{{ $ref->ref_type->value }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </dd>
                </div>

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

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Options</dt>
                    <dd class="mt-1 flex flex-wrap gap-1">
                        @foreach ($options->summaryFlags() as $flag)
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $flag }}</span>
                        @endforeach
                    </dd>
                </div>
            </dl>

            @if ($autoSelectedPatches->isNotEmpty())
                <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <p class="font-medium">
                        {{ $autoSelectedPatches->count() }} active
                        {{ Str::plural('patch', $autoSelectedPatches->count()) }}
                        for these repositories {{ $autoSelectedPatches->count() === 1 ? 'is' : 'are' }} not selected:
                    </p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5">
                        @foreach ($autoSelectedPatches as $patch)
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
                                <li class="rounded border border-slate-200 bg-slate-50 px-3 py-2">
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

        <form method="POST" action="{{ route('deployments.store') }}" class="flex items-center gap-3">
            @csrf

            {{-- The reviewed payload, resubmitted verbatim so what was confirmed is
                 what runs. --}}
            @foreach ($payload['refs'] as $index => $ref)
                <input type="hidden" name="refs[{{ $index }}][repository_id]" value="{{ $ref['repository_id'] }}">
                <input type="hidden" name="refs[{{ $index }}][ref_type]" value="{{ $ref['ref_type'] }}">
                <input type="hidden" name="refs[{{ $index }}][ref_value]" value="{{ $ref['ref_value'] }}">
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

            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                Start deployment
            </button>
            <a href="{{ route('deployments.create') }}" class="text-sm text-slate-500 hover:text-slate-900">Back to the wizard</a>
        </form>
    </div>
</x-layouts.app>
