{{--
    The checkout picker, shared by the deploy and undeploy wizards.

    The unit of selection is a checkout — one repository in one core version — so
    "all versions" is a bulk toggle over a repository's rows rather than a mode.
    Each row carries its own ref, which is what lets 1.45 go to REL1_45 and 1.46
    to REL1_46 in a single submission.
--}}
@php
    /** @var \App\Enums\DeploymentIntent $intent */
    $isUndeploy = $intent === \App\Enums\DeploymentIntent::Undeploy;
@endphp

@if ($repositoriesByType->isEmpty())
    <p class="text-sm text-slate-500">
        @if ($isUndeploy)
            There is nothing you have permission to remove. Removal is a separate grant from deployment —
            ask an administrator for <code>deploy.undeploy_extension</code> or <code>deploy.undeploy_skin</code>.
        @else
            You do not have permission to deploy any registered repository. Ask an administrator for a
            <code>deploy.*</code> permission.
        @endif
    </p>
@endif

@foreach ($types as $type)
    @php $repositories = $repositoriesByType->get($type->value, collect()); @endphp

    @if ($repositories->isNotEmpty())
        <section class="mb-6 last:mb-0">
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                {{ $type->pluralLabel() }}
            </h3>

            <ul class="divide-y divide-slate-100 rounded-md border border-slate-200">
                @foreach ($repositories as $repository)
                    @php $checkouts = $checkoutsByRepository->get($repository->getKey(), collect()); @endphp

                    <li class="p-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" @click="toggleRepository({{ $repository->getKey() }})"
                                    class="rounded border px-2 py-0.5 text-xs font-medium"
                                    x-bind:class="{
                                        'border-slate-900 bg-slate-900 text-white': repositoryState({{ $repository->getKey() }}) === 'all',
                                        'border-slate-400 bg-slate-100 text-slate-700': repositoryState({{ $repository->getKey() }}) === 'some',
                                        'border-slate-300 text-slate-600': repositoryState({{ $repository->getKey() }}) === 'none',
                                    }">
                                <span x-show="repositoryState({{ $repository->getKey() }}) === 'all'">All versions ✓</span>
                                <span x-show="repositoryState({{ $repository->getKey() }}) !== 'all'">All versions</span>
                            </button>

                            <span class="text-sm font-medium">{{ $repository->name }}</span>

                            <span class="text-xs text-slate-500">
                                {{ $checkouts->count() }} {{ Str::plural('version', $checkouts->count()) }}
                            </span>

                            <span class="ml-auto text-xs text-slate-500"
                                  x-show="selectedCountFor({{ $repository->getKey() }}) > 0"
                                  x-text="selectedCountFor({{ $repository->getKey() }}) + ' selected'"></span>
                        </div>

                        <ul class="mt-2 space-y-2 pl-2">
                            @foreach ($checkouts as $checkout)
                                <li class="rounded border border-slate-100 p-2"
                                    x-bind:class="isSelected({{ $checkout->getKey() }}) ? 'bg-slate-50' : ''">
                                    <label class="flex flex-wrap items-center gap-2 text-sm">
                                        <input type="checkbox" class="rounded border-slate-300"
                                               x-bind:checked="isSelected({{ $checkout->getKey() }})"
                                               @change="toggle({{ $checkout->getKey() }})">

                                        <span class="font-medium">{{ $checkout->versionLabel() }}</span>

                                        <code class="font-mono text-xs text-slate-500">{{ $checkout->path }}</code>

                                        <span class="rounded px-1.5 py-0.5 text-xs ring-1 ring-inset {{ $checkout->status->badgeClasses() }}">
                                            {{ $checkout->status->label() }}
                                        </span>

                                        @unless ($isUndeploy)
                                            <span class="text-xs text-slate-500">{{ $checkout->refModeSummary() }}</span>
                                        @endunless
                                    </label>

                                    <input type="hidden" name="items[{{ $checkout->getKey() }}][repository_version_id]"
                                           value="{{ $checkout->getKey() }}"
                                           x-bind:disabled="! isSelected({{ $checkout->getKey() }})">

                                    @unless ($isUndeploy)
                                        <input type="hidden" name="items[{{ $checkout->getKey() }}][ref_type]"
                                               x-bind:value="refType({{ $checkout->getKey() }})"
                                               x-bind:disabled="! isSelected({{ $checkout->getKey() }})">

                                        <div x-show="isSelected({{ $checkout->getKey() }})" x-cloak
                                             class="mt-2 flex flex-wrap items-end gap-3 pl-6">
                                            <div class="flex rounded-md border border-slate-300 text-xs">
                                                <button type="button" class="px-2 py-1"
                                                        x-bind:class="refType({{ $checkout->getKey() }}) === 'branch' ? 'bg-slate-900 text-white' : 'text-slate-600'"
                                                        @click="setRefType({{ $checkout->getKey() }}, 'branch')">Branch</button>
                                                <button type="button" class="px-2 py-1"
                                                        x-bind:class="refType({{ $checkout->getKey() }}) === 'commit' ? 'bg-slate-900 text-white' : 'text-slate-600'"
                                                        @click="setRefType({{ $checkout->getKey() }}, 'commit')">Commit</button>
                                            </div>

                                            {{-- Pre-filled with this checkout's own pin, which is the
                                                 answer the operator wants most of the time. Branches are
                                                 not listed eagerly: with a hundred checkouts on the page
                                                 that would be a hundred lookups before anything is even
                                                 selected. --}}
                                            <div x-show="refType({{ $checkout->getKey() }}) === 'branch'" class="w-72">
                                                <input type="text" x-model="selected[{{ $checkout->getKey() }}].refValue"
                                                       placeholder="{{ $checkout->resolvedRefValue() ?? 'branch name' }}"
                                                       class="block w-full rounded-md bg-white px-3 py-2 font-mono text-sm ring-1 ring-inset ring-slate-300">
                                            </div>

                                            <div x-show="refType({{ $checkout->getKey() }}) === 'commit'" x-cloak class="w-96 space-y-2">
                                                <select x-model="selected[{{ $checkout->getKey() }}].refValue"
                                                        class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">
                                                    <option value="">— pick a recent commit —</option>
                                                    <template x-for="commit in (commits[{{ $checkout->getKey() }}] ?? [])" :key="commit.value">
                                                        <option x-bind:value="commit.value" x-text="commit.label"></option>
                                                    </template>
                                                </select>
                                                <input type="text" placeholder="…or paste a commit SHA"
                                                       x-model="selected[{{ $checkout->getKey() }}].refValue"
                                                       class="block w-full rounded-md bg-white px-3 py-2 font-mono text-sm ring-1 ring-inset ring-slate-300">
                                                <p x-show="loading[{{ $checkout->getKey() }}]" class="text-xs text-slate-500">Loading commits…</p>
                                            </div>

                                            <input type="hidden" name="items[{{ $checkout->getKey() }}][ref_value]"
                                                   x-bind:value="selected[{{ $checkout->getKey() }}]?.refValue ?? ''"
                                                   x-bind:disabled="! isSelected({{ $checkout->getKey() }})">
                                        </div>
                                    @endunless
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endforeach
