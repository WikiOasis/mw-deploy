<x-layouts.app title="Register repository">
    <div class="mx-auto max-w-2xl">
        <x-card title="Register a repository"
                subtitle="Creates the registry row and queues a deployment that clones it into the versions you pick.">
            <form method="POST" action="{{ route('repositories.store') }}" class="space-y-4"
                  x-data="{ type: '{{ old('type', 'extension') }}' }">
                @csrf

                <x-field label="Name" name="name" required
                         hint="Becomes the directory name, e.g. Echo → versions/1.45/extensions/Echo.">
                    <x-input name="name" value="{{ old('name') }}" required autofocus />
                </x-field>

                <x-field label="Type" name="type" required>
                    <select name="type" x-model="type" required
                            class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}" @selected(old('type', 'extension') === $type->value)>
                                {{ $type->label() }}
                            </option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="Git remote" name="git_url" required
                         hint="https:// or git@host:path. Checked for reachability before anything is written.">
                    <x-input name="git_url" value="{{ old('git_url') }}" required
                             placeholder="https://github.com/wikioasis/mediawiki-extensions-Echo" />
                </x-field>

                <x-field label="Default branch" name="default_branch" required
                         hint="Used when a checkout follows the default branch, and as the fallback pin.">
                    <x-input name="default_branch" value="{{ old('default_branch', 'master') }}" required />
                </x-field>

                <div x-show="type === 'extension' || type === 'skin'" x-cloak>
                    <p class="text-sm font-medium text-slate-700">
                        Add it to these core versions <span class="text-rose-600">*</span>
                    </p>
                    <p class="mb-2 text-xs text-slate-500">
                        One checkout per version. Each gets its own pin, defaulting to the branch above — change any of
                        them afterwards from the repository page.
                    </p>

                    @if ($versions->isEmpty())
                        <p class="text-sm text-rose-600">
                            No core versions exist yet. Cut one under Versions first.
                        </p>
                    @else
                        <ul class="space-y-2 rounded-md border border-slate-200 p-3">
                            @foreach ($versions as $version)
                                <li class="flex flex-wrap items-center gap-3">
                                    <label class="flex min-w-28 items-center gap-2 text-sm">
                                        <input type="checkbox" name="versions[]" value="{{ $version->getKey() }}"
                                               @checked(in_array($version->getKey(), (array) old('versions', []), false))
                                               class="rounded border-slate-300">
                                        <span class="font-medium">{{ $version->version }}</span>
                                    </label>

                                    <div class="w-40">
                                        <select name="refs[{{ $version->getKey() }}][ref_mode]"
                                                class="block w-full rounded-md bg-white px-2 py-1 text-xs ring-1 ring-inset ring-slate-300">
                                            @foreach (\App\Enums\RefMode::cases() as $mode)
                                                <option value="{{ $mode->value }}" @selected($mode === \App\Enums\RefMode::Pinned)>
                                                    {{ $mode->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div class="w-44">
                                        <x-input name="refs[{{ $version->getKey() }}][ref]" placeholder="ref for this version"
                                                 class="py-1 font-mono text-xs" />
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div x-show="type === 'core'" x-cloak
                     class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                    MediaWiki core is registered once. Its per-version checkouts come from cutting a version under
                    <a href="{{ route('versions.index') }}" class="underline">Versions</a>, not from this form.
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="in_use" value="1" @checked(old('in_use')) class="rounded border-slate-300">
                    The farm actually enables this (per mw-config)
                </label>

                <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                    <p class="font-medium text-slate-700">What happens on save</p>
                    <p class="mt-1">
                        The remote is checked with <code class="font-mono">git ls-remote</code> first, so a typo fails
                        here rather than as a puzzling deployment failure later. Then a staging-only deployment is
                        queued to clone each checkout — reviewable, and undoable by rolling it back.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                        Register and clone
                    </button>
                    <a href="{{ route('repositories.index') }}" class="text-sm text-slate-500 hover:text-slate-900">Cancel</a>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
