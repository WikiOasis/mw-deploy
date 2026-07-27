<x-layouts.app title="New deployment">
    <form method="POST" action="{{ route('deployments.review') }}"
          x-data="deploymentWizard({
              refsUrlTemplate: '{{ route('repositories.refs', ['repository' => '__ID__']) }}',
              defaultBranches: {{ Js::from($repositoriesByType->flatten()->mapWithKeys(fn ($r) => [$r->id => $r->default_branch])) }},
              canTargetProduction: {{ Js::from($canTargetProduction) }},
          })"
          class="space-y-6">
        @csrf

        {{-- Steps 1 and 2: what to update, and which ref per repo. --}}
        <x-card title="1 & 2 — What to update, and at which ref"
                subtitle="Only repositories you have permission to deploy are listed.">
            @if ($repositoriesByType->isEmpty())
                <p class="text-sm text-slate-500">
                    You do not have permission to deploy any registered repository. Ask an administrator for a
                    <code>deploy.*</code> permission.
                </p>
            @endif

            @foreach ($types as $type)
                @php $repositories = $repositoriesByType->get($type->value, collect()); @endphp

                @if ($repositories->isNotEmpty())
                    <section class="mb-6 last:mb-0">
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ $type->label() }}
                        </h3>

                        <ul class="divide-y divide-slate-100 rounded-md border border-slate-200">
                            @foreach ($repositories as $repository)
                                <li class="p-3" x-bind:class="isSelected({{ $repository->id }}) ? 'bg-slate-50' : ''">
                                    <label class="flex items-center gap-2 text-sm font-medium">
                                        <input type="checkbox" class="rounded border-slate-300"
                                               x-bind:checked="isSelected({{ $repository->id }})"
                                               @change="toggle({{ $repository->id }})">
                                        {{ $repository->displayName() }}
                                        <code class="ml-2 font-mono text-xs font-normal text-slate-500">{{ $repository->path }}</code>
                                    </label>

                                    <div x-show="isSelected({{ $repository->id }})" x-cloak class="mt-3 pl-6">
                                        <input type="hidden" name="refs[{{ $repository->id }}][repository_id]"
                                               value="{{ $repository->id }}"
                                               x-bind:disabled="! isSelected({{ $repository->id }})">
                                        <input type="hidden" name="refs[{{ $repository->id }}][ref_type]"
                                               x-bind:value="refType({{ $repository->id }})"
                                               x-bind:disabled="! isSelected({{ $repository->id }})">

                                        <div class="flex flex-wrap items-end gap-3">
                                            <div class="flex rounded-md border border-slate-300 text-xs">
                                                <button type="button"
                                                        class="px-2 py-1"
                                                        x-bind:class="refType({{ $repository->id }}) === 'branch' ? 'bg-slate-900 text-white' : 'text-slate-600'"
                                                        @click="setRefType({{ $repository->id }}, 'branch')">Branch</button>
                                                <button type="button"
                                                        class="px-2 py-1"
                                                        x-bind:class="refType({{ $repository->id }}) === 'commit' ? 'bg-slate-900 text-white' : 'text-slate-600'"
                                                        @click="setRefType({{ $repository->id }}, 'commit')">Commit</button>
                                            </div>

                                            {{-- Branch mode: pick from the staging clone's remote branches. --}}
                                            <div x-show="refType({{ $repository->id }}) === 'branch'" class="w-72">
                                                <select x-model="selected[{{ $repository->id }}].refValue"
                                                        class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">
                                                    @foreach ($branchesByRepository[$repository->id] ?? [] as $branch)
                                                        <option value="{{ $branch->value }}">
                                                            {{ $branch->value }}@if ($branch->isDefault) (default)@endif
                                                        </option>
                                                    @endforeach
                                                    @if (($branchesByRepository[$repository->id] ?? []) === [])
                                                        <option value="{{ $repository->default_branch }}">{{ $repository->default_branch }}</option>
                                                    @endif
                                                </select>
                                            </div>

                                            {{-- Commit mode: recent commits, or type a SHA. --}}
                                            <div x-show="refType({{ $repository->id }}) === 'commit'" x-cloak class="w-96 space-y-2">
                                                <select x-model="selected[{{ $repository->id }}].refValue"
                                                        class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">
                                                    <option value="">— pick a recent commit —</option>
                                                    <template x-for="commit in (commits[{{ $repository->id }}] ?? [])" :key="commit.value">
                                                        <option x-bind:value="commit.value" x-text="commit.label"></option>
                                                    </template>
                                                </select>
                                                <input type="text" placeholder="…or paste a commit SHA"
                                                       x-model="selected[{{ $repository->id }}].refValue"
                                                       class="block w-full rounded-md bg-white px-3 py-2 font-mono text-sm ring-1 ring-inset ring-slate-300">
                                                <p x-show="loading[{{ $repository->id }}]" class="text-xs text-slate-500">Loading commits…</p>
                                            </div>

                                            <input type="hidden" name="refs[{{ $repository->id }}][ref_value]"
                                                   x-bind:value="selected[{{ $repository->id }}]?.refValue ?? ''"
                                                   x-bind:disabled="! isSelected({{ $repository->id }})">
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            @endforeach
        </x-card>

        {{-- Step 3: patches. --}}
        <x-card title="3 — Patches"
                subtitle="Target path and repository live on the patch itself, so you never retype the target directory.">
            @if ($patches->isEmpty())
                <p class="text-sm text-slate-500">No active patches are registered.</p>
            @else
                <ul class="space-y-2">
                    @foreach ($patches as $patch)
                        <li>
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="patches[]" value="{{ $patch->id }}"
                                       class="mt-1 rounded border-slate-300"
                                       x-bind:checked="{{ Js::from($patch->target_repo_id !== null) }} && isSelected({{ $patch->target_repo_id ?? 0 }})">
                                <span>
                                    <span class="font-medium">{{ $patch->name }}</span>
                                    <code class="ml-2 font-mono text-xs text-slate-500">{{ $patch->target_path }}</code>
                                    @if ($patch->targetRepository)
                                        <span class="ml-2 text-xs text-slate-500">for {{ $patch->targetRepository->displayName() }}</span>
                                    @else
                                        <span class="ml-2 text-xs text-slate-500">freeform</span>
                                    @endif
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
                    Patches whose target repository is selected above are ticked automatically, so a patch is not
                    silently dropped just because someone forgot the flag. Untick to skip one deliberately.
                </p>
            @endif
        </x-card>

        {{-- Steps 4 and 5: targets and options. --}}
        <x-card title="4 & 5 — Targets and options">
            <div class="space-y-5">
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="staging_only" value="1" x-model="stagingOnly"
                           @disabled(! $canTargetProduction) class="mt-1 rounded border-slate-300">
                    <span>
                        <span class="font-medium">Staging only</span>
                        <span class="block text-xs text-slate-500">
                            Prepare and validate on staging without touching any appserver.
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
                                {{ Str::plural('proxy', $proxies->count()) }} before syncing, and repool afterwards.
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

                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="l10n" value="1" @checked(old('l10n')) class="mt-1 rounded border-slate-300">
                    <span>
                        <span class="font-medium">Rebuild l10n cache</span>
                        <span class="block text-xs text-slate-500">Runs on staging and on each appserver.</span>
                    </span>
                </label>

                @if ($canForce)
                    <label class="flex items-start gap-2 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm">
                        <input type="checkbox" name="force" value="1" @checked(old('force')) class="mt-1 rounded border-rose-300">
                        <span>
                            <span class="font-medium text-rose-900">Force — ignore canary failures</span>
                            <span class="block text-xs text-rose-800">
                                The deployment will not stop or prompt when a canary check fails, and no automatic
                                rollback will be enqueued. This is the most dangerous option in the portal.
                            </span>
                        </span>
                    </label>
                @endif
            </div>
        </x-card>

        <div class="flex items-center gap-3">
            <button type="submit" x-bind:disabled="! canSubmit()"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-40">
                Review the plan
            </button>
            <span class="text-sm text-slate-500" x-text="selectedCount() + ' repositor' + (selectedCount() === 1 ? 'y' : 'ies') + ' selected'"></span>
        </div>
    </form>
</x-layouts.app>
