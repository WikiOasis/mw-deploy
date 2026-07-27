<x-layouts.app title="MediaWiki versions">
    <div class="space-y-6">
        <x-card title="MediaWiki core versions"
                subtitle="Each version is one versions/<ver> subtree containing core plus its own copy of every extension and skin.">
            @if ($versions->isEmpty())
                <p class="text-sm text-slate-500">
                    No versions registered. Register a MediaWiki core repository first, then cut a version below.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="py-2 pr-4">Version</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Checkouts</th>
                                <th class="py-2 pr-4">Built from</th>
                                <th class="py-2 pr-4">Created</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($versions as $version)
                                @php
                                    $present = $version->checkouts->where('status', \App\Enums\PresenceStatus::Present);
                                @endphp
                                <tr>
                                    <td class="py-2 pr-4">
                                        <a href="{{ route('versions.show', $version) }}" class="font-medium hover:underline">
                                            {{ $version->version }}
                                        </a>
                                        <code class="ml-2 font-mono text-xs text-slate-500">{{ $version->relativePath() }}</code>
                                    </td>
                                    <td class="py-2 pr-4">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $version->status->badgeClasses() }}">
                                            {{ $version->status->label() }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4 text-slate-600">
                                        {{ $present->count() }} on disk
                                        @if ($version->checkouts->count() > $present->count())
                                            <span class="text-xs text-slate-400">
                                                ({{ $version->checkouts->count() - $present->count() }} removed)
                                            </span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-slate-500">{{ $version->createdFrom?->version ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-slate-500">
                                        {{ $version->created_at?->diffForHumans() }}
                                        @if ($version->creator)
                                            <span class="block text-xs">by {{ $version->creator->name }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 text-right">
                                        <a href="{{ route('versions.show', $version) }}" class="text-slate-500 hover:text-slate-900">
                                            Details
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

        @can('create', \App\Models\MediaWikiVersion::class)
            <x-card title="Cut a new version"
                    subtitle="Reconstructs the new tree from an existing version: scaffold, then clone core and every extension and skin the source has.">
                @if ($coreRepository === null)
                    <p class="text-sm text-rose-600">
                        No MediaWiki core repository is registered, so there is nothing to build a version from.
                        Register one under Repositories first.
                    </p>
                @else
                    <form method="POST" action="{{ route('versions.store') }}" class="grid gap-4 sm:grid-cols-2">
                        @csrf

                        <x-field label="New version" name="version" required hint="Becomes versions/<ver> on disk.">
                            <x-input name="version" value="{{ old('version') }}" placeholder="1.46" required />
                        </x-field>

                        <x-field label="Copy the extension and skin set from" name="source_id"
                                 hint="Every extension and skin currently on disk in that version is registered and cloned into the new one.">
                            <select name="source_id" class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">
                                <option value="">— core only, no extensions —</option>
                                @foreach ($versions->where('status', \App\Enums\PresenceStatus::Present) as $version)
                                    <option value="{{ $version->getKey() }}" @selected((int) old('source_id') === $version->getKey())>
                                        {{ $version->version }}
                                        ({{ $version->checkouts->where('status', \App\Enums\PresenceStatus::Present)->count() }} checkouts)
                                    </option>
                                @endforeach
                            </select>
                        </x-field>

                        <x-field label="Core ref" name="core_ref" required
                                 hint="The release branch or tag for the new version, e.g. REL1_46.">
                            <x-input name="core_ref" value="{{ old('core_ref') }}" placeholder="REL1_46" required class="font-mono" />
                        </x-field>

                        <x-field label="Parallelism" name="parallel" hint="Only relevant once you roll out.">
                            <x-input type="number" name="parallel" min="1" max="{{ $maxParallel }}"
                                     value="{{ old('parallel', 1) }}" />
                        </x-field>

                        <div class="sm:col-span-2 space-y-2">
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="staging_only" value="1"
                                       @checked(old('staging_only', true)) class="mt-1 rounded border-slate-300">
                                <span>
                                    <span class="font-medium">Staging only (recommended)</span>
                                    <span class="block text-xs text-slate-500">
                                        A brand new version serves no traffic yet, so build it and check it on staging
                                        first, then roll it out as a separate deployment.
                                    </span>
                                </span>
                            </label>

                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="l10n" value="1" @checked(old('l10n')) class="rounded border-slate-300">
                                Rebuild the l10n cache afterwards
                            </label>
                        </div>

                        <div class="sm:col-span-2 rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                            <p class="font-medium text-slate-700">Each copied checkout keeps the source version's pin.</p>
                            <p class="mt-1">
                                That is right for extensions tracking a moving branch and usually wrong for anything
                                pinned to a release branch, so the review screen lists every ref before a single clone
                                happens. Adjust individual pins afterwards under Repositories.
                            </p>
                            <p class="mt-1">
                                A new version roughly doubles the tree on staging and on every appserver. Check disk
                                before starting.
                            </p>
                        </div>

                        <div class="sm:col-span-2">
                            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                                Review and build
                            </button>
                        </div>
                    </form>
                @endif
            </x-card>
        @endcan

        @unless ($wikiVersionCheckEnabled)
            <div class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                <p class="font-medium">The wiki-version safety check is disabled.</p>
                <p class="mt-1">
                    <code class="font-mono">MWDEPLOY_REQUIRE_WIKIVERSION_CHECK</code> is off, so undeploying a version
                    will not verify that no wiki still runs on it. Turn it back on unless
                    <code class="font-mono">{{ $wikiVersionsPath }}</code> genuinely cannot be read.
                </p>
            </div>
        @endunless
    </div>
</x-layouts.app>
