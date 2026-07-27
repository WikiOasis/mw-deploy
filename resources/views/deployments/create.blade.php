@php
    $resolvedRefs = $checkoutsByRepository->flatten()
        ->mapWithKeys(fn ($checkout) => [$checkout->getKey() => $checkout->resolvedRefValue() ?? '']);
    $checkoutIds = $checkoutsByRepository->map(fn ($checkouts) => $checkouts->modelKeys());
@endphp

<x-layouts.app title="New deployment">
    <form method="POST" action="{{ route('deployments.review') }}"
          x-data="deploymentWizard({
              refsUrlTemplate: '{{ route('checkouts.refs', ['checkout' => '__ID__']) }}',
              resolvedRefs: {{ Js::from($resolvedRefs) }},
              checkoutsByRepository: {{ Js::from($checkoutIds) }},
              canTargetProduction: {{ Js::from($canTargetProduction) }},
          })"
          class="space-y-6">
        @csrf
        <input type="hidden" name="intent" value="{{ \App\Enums\DeploymentIntent::Deploy->value }}">

        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold tracking-tight">New deployment</h1>
            @can(\App\Support\Permissions::UNDEPLOY_EXTENSION)
                <a href="{{ route('deployments.undeploy') }}" class="text-sm text-slate-500 hover:text-slate-900">
                    Undeploy instead →
                </a>
            @endcan
        </div>

        <x-card title="1 — What to deploy, and at which ref"
                subtitle="Pick individual versions, or use “All versions” to deploy one repository everywhere it exists.">
            <div class="mb-4 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                Each version's own pinned ref is filled in for you, so “All versions” sends 1.45 to its
                pin and 1.46 to its own. Override any row individually, or use the bulk controls below.
            </div>

            @include('deployments._picker')

            <div x-show="selectedCount() > 0" x-cloak
                 class="mt-4 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4">
                <div class="w-72">
                    <x-field label="Apply one ref to everything selected" name="bulk_ref"
                             hint="For deploying the same branch across every version at once.">
                        <x-input x-model="bulkRef" placeholder="master" class="font-mono" />
                    </x-field>
                </div>
                <button type="button" class="mb-0.5 rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                        @click="applyRefToSelection(bulkRef)">
                    Apply to all selected
                </button>
                <button type="button" class="mb-0.5 rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                        @click="resetToPins()">
                    Reset to each version's pin
                </button>
            </div>
        </x-card>

        <x-card title="2 — Patches"
                subtitle="Target path and checkout live on the patch itself, so you never retype the target directory.">
            @if ($patches->isEmpty())
                <p class="text-sm text-slate-500">No active patches are registered.</p>
            @else
                <ul class="space-y-2">
                    @foreach ($patches as $patch)
                        <li>
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="patches[]" value="{{ $patch->getKey() }}"
                                       class="mt-1 rounded border-slate-300"
                                       x-bind:checked="{{ Js::from($patch->target_repository_version_id !== null) }}
                                            && isSelected({{ $patch->target_repository_version_id ?? 0 }})">
                                <span>
                                    <span class="font-medium">{{ $patch->name }}</span>
                                    <code class="ml-2 font-mono text-xs text-slate-500">{{ $patch->target_path }}</code>
                                    <span class="ml-2 text-xs text-slate-500">for {{ $patch->targetLabel() }}</span>
                                    @if ($patch->last_check_ok === false)
                                        <span class="ml-2 rounded bg-rose-100 px-1.5 py-0.5 text-xs text-rose-800">last dry run failed</span>
                                    @endif
                                    @if ($patch->description)
                                        <span class="block text-xs text-slate-500">{{ $patch->description }}</span>
                                    @endif
                                </span>
                            </label>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-slate-500">
                    Patches whose target checkout is selected above are ticked automatically, so a patch is not
                    silently dropped just because someone forgot it. Untick to skip one deliberately.
                </p>
            @endif
        </x-card>

        <x-card title="3 — Targets and options">
            @include('deployments._options')
        </x-card>

        <div class="flex items-center gap-3">
            <button type="submit" x-bind:disabled="! canSubmit()"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-40">
                Review the plan
            </button>
            <span class="text-sm text-slate-500"
                  x-text="selectedCount() + ' checkout' + (selectedCount() === 1 ? '' : 's') + ' selected'"></span>
        </div>
    </form>
</x-layouts.app>
