{{-- Target and rollout options, shared by the deploy and undeploy wizards. --}}
@php $isUndeploy = ($intent ?? null) === \App\Enums\DeploymentIntent::Undeploy; @endphp

<div class="space-y-5">
    <label class="flex items-start gap-2 text-sm">
        <input type="checkbox" name="staging_only" value="1" x-model="stagingOnly"
               @disabled(! $canTargetProduction) class="mt-1 rounded border-slate-300">
        <span>
            <span class="font-medium">Staging only</span>
            <span class="block text-xs text-slate-500">
                @if ($isUndeploy)
                    Remove from the staging tree only, leaving the appservers untouched.
                @else
                    Prepare and validate on staging without touching any appserver.
                @endif
                @unless ($canTargetProduction)
                    You only have permission for staging-only deployments, so this is fixed on.
                @endunless
            </span>
        </span>
    </label>

    <div x-show="! stagingOnly" x-cloak class="space-y-5">
        <div>
            <p class="text-sm font-medium text-slate-700">Appservers</p>
            <label class="mt-1 flex items-center gap-2 text-sm">
                <input type="checkbox" x-model="allServers" class="rounded border-slate-300">
                All active appservers ({{ $appservers->count() }})
            </label>

            <ul x-show="! allServers" x-cloak class="mt-2 grid gap-1 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($appservers as $server)
                    <li>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="servers[]" value="{{ $server->hostname }}"
                                   class="rounded border-slate-300">
                            <code class="font-mono text-xs">{{ $server->hostname }}</code>
                        </label>
                    </li>
                @endforeach
            </ul>

            @if ($appservers->isEmpty())
                <p class="mt-1 text-xs text-rose-600">
                    No active appservers are registered. Add them under Targets first.
                </p>
            @endif
        </div>

        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="rollout" value="1" x-model="rollout" class="mt-1 rounded border-slate-300">
            <span>
                <span class="font-medium">Rollout (depool / repool)</span>
                <span class="block text-xs text-slate-500">
                    Depool each server from all {{ $proxies->count() }} registered
                    {{ Str::plural('proxy', $proxies->count()) }} before touching it, and repool afterwards.
                </span>
            </span>
        </label>

        <div class="w-40">
            <x-field label="Parallelism" name="parallel" hint="Servers updated at once.">
                <x-input type="number" name="parallel" min="1" max="{{ $maxParallel }}"
                         value="{{ old('parallel', $defaultParallel) }}" required />
            </x-field>
        </div>
    </div>

    <div x-show="stagingOnly" x-cloak>
        <input type="hidden" name="parallel" value="1">
    </div>

    @unless ($isUndeploy)
        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="l10n" value="1" @checked(old('l10n')) class="mt-1 rounded border-slate-300">
            <span>
                <span class="font-medium">Rebuild l10n cache</span>
                <span class="block text-xs text-slate-500">Runs on staging and on each appserver.</span>
            </span>
        </label>
    @endunless

    @if ($canForce)
        <label class="flex items-start gap-2 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm">
            <input type="checkbox" name="force" value="1" @checked(old('force')) class="mt-1 rounded border-rose-300">
            <span>
                <span class="font-medium text-rose-900">Force — ignore canary failures</span>
                <span class="block text-xs text-rose-800">
                    The deployment will not stop or prompt when a canary check fails, and no automatic rollback
                    will be enqueued. This is the most dangerous option in the portal.
                </span>
            </span>
        </label>
    @endif
</div>
