<x-layouts.app title="Register repository">
    <div class="mx-auto max-w-2xl">
        <x-card title="Register a repository"
                subtitle="Saving clones the repo into the staging tree, so it is usable in the ref picker immediately.">
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

                <x-field label="Git remote" name="git_url" required hint="https:// or git@host:path.">
                    <x-input name="git_url" value="{{ old('git_url') }}" required
                             placeholder="https://github.com/wikioasis/mediawiki-extensions-Echo" />
                </x-field>

                <x-field label="Default branch" name="default_branch" required>
                    <x-input name="default_branch" value="{{ old('default_branch', 'master') }}" required />
                </x-field>

                <div x-show="type !== 'config'">
                    <x-field label="MediaWiki core version" name="core_version"
                             hint="Required for a core version; optional for extensions and skins that live outside a versions/ subtree.">
                        <x-input name="core_version" value="{{ old('core_version') }}" placeholder="1.45"
                                 list="known-core-versions" />
                        <datalist id="known-core-versions">
                            @foreach ($coreVersions as $version)
                                <option value="{{ $version }}"></option>
                            @endforeach
                        </datalist>
                    </x-field>
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="in_use" value="1" @checked(old('in_use')) class="rounded border-slate-300">
                    The farm actually enables this (per mw-config)
                </label>

                <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                    <p class="font-medium text-slate-700">What happens on save</p>
                    <p class="mt-1">
                        The portal runs <code class="font-mono">mwdeploy-shim repo-register</code> on the staging host to
                        clone the remote into the derived path. If the clone fails the registry entry is not created, so
                        a broken remote cannot leave an entry behind that breaks every later deploy.
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
