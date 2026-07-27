@php
    $resolvedRefs = $checkoutsByRepository->flatten()
        ->mapWithKeys(fn ($checkout) => [$checkout->getKey() => '']);
    $checkoutIds = $checkoutsByRepository->map(fn ($checkouts) => $checkouts->modelKeys());
@endphp

<x-layouts.app title="Undeploy">
    <form method="POST" action="{{ route('deployments.review') }}"
          x-data="deploymentWizard({
              refsUrlTemplate: '{{ route('checkouts.refs', ['checkout' => '__ID__']) }}',
              resolvedRefs: {{ Js::from($resolvedRefs) }},
              checkoutsByRepository: {{ Js::from($checkoutIds) }},
              canTargetProduction: {{ Js::from($canTargetProduction) }},
              undeploy: true,
          })"
          class="space-y-6">
        @csrf
        <input type="hidden" name="intent" value="{{ \App\Enums\DeploymentIntent::Undeploy->value }}">

        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold tracking-tight">Undeploy</h1>
            <a href="{{ route('deployments.create') }}" class="text-sm text-slate-500 hover:text-slate-900">
                ← Deploy instead
            </a>
        </div>

        <div class="rounded-md border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-900">
            <p class="font-medium">This deletes directories from staging and every appserver.</p>
            <p class="mt-1">
                Each selected checkout is removed with <code class="font-mono">rm -rf</code> on every host, one call
                per host so a failure is attributable. The shim refuses any path outside the configured deploy
                root, and refuses a whole core version unless that is what you asked for.
            </p>
            <p class="mt-1">
                This is reversible: the registry row survives, the undo point records where each checkout was, and
                rolling this deployment back restores every one of them at the ref it was on. Removing a whole core
                version is a different action, on the
                <a href="{{ route('versions.index') }}" class="underline">Versions</a> page.
            </p>
        </div>

        <x-card title="1 — What to remove"
                subtitle="Only checkouts currently on disk are listed. Use “All versions” to remove a repository everywhere.">
            @include('deployments._picker')
        </x-card>

        <x-card title="2 — Targets and options">
            @include('deployments._options')
        </x-card>

        <div class="flex items-center gap-3">
            <button type="submit" x-bind:disabled="! canSubmit()"
                    class="rounded-md bg-orange-600 px-4 py-2 text-sm font-medium text-white hover:bg-orange-500 disabled:cursor-not-allowed disabled:opacity-40">
                Review what will be removed
            </button>
            <span class="text-sm text-slate-500"
                  x-text="selectedCount() + ' checkout' + (selectedCount() === 1 ? '' : 's') + ' selected'"></span>
        </div>
    </form>
</x-layouts.app>
