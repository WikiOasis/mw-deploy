@php
    /** @var \App\Models\Patch|null $patch */
    $patch ??= null;
@endphp

<x-field label="Name" name="name" required>
    <x-input name="name" value="{{ old('name', $patch->name ?? '') }}" required autofocus />
</x-field>

<x-field label="Description" name="description"
         hint="Why this patch exists, and when it can be dropped. Future you will want this.">
    <textarea name="description" rows="3"
              class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">{{ old('description', $patch->description ?? '') }}</textarea>
</x-field>

<x-field label="Target checkout" name="target_repository_version_id"
         hint="A patch is written against the files as they exist in one core version, so the target is a specific checkout. Leave blank for a freeform patch.">
    <select name="target_repository_version_id" class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">
        <option value="">— freeform —</option>
        @foreach ($checkouts as $checkout)
            <option value="{{ $checkout->getKey() }}"
                    @selected((int) old('target_repository_version_id', $patch->target_repository_version_id ?? 0) === $checkout->getKey())>
                {{ $checkout->displayName() }} ({{ $checkout->repository?->type->label() }})
            </option>
        @endforeach
    </select>
</x-field>

<x-field label="Target path" name="target_path" required
         hint="Directory the patch applies in, relative to the MediaWiki root — stored once here instead of retyped per deploy.">
    <x-input name="target_path" value="{{ old('target_path', $patch->target_path ?? '') }}" required
             placeholder="versions/1.45/extensions/Echo" class="font-mono" />
</x-field>

<x-field label="Patch format" name="format" required
         hint="Decides whether the dry-run check shells to `patch --dry-run` or `git apply --check`.">
    <select name="format" required class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">
        <option value="unified" @selected(old('format', $patch->format ?? 'unified') === 'unified')>Unified diff (patch -p1)</option>
        <option value="git" @selected(old('format', $patch->format ?? 'unified') === 'git')>Git-format patch (git apply)</option>
    </select>
</x-field>

<x-field label="Patch file" name="patch_file" :required="$patch === null"
         hint="{{ $patch === null ? 'Up to 2 MB.' : 'Leave empty to keep the current file'.($patch->original_filename ? ' ('.$patch->original_filename.')' : '').'.' }}">
    <input type="file" name="patch_file" accept=".patch,.diff,text/plain"
           class="block w-full text-sm" @required($patch === null)>
</x-field>

<label class="flex items-start gap-2 text-sm">
    <input type="checkbox" name="active" value="1" @checked(old('active', $patch->active ?? true)) class="mt-1 rounded border-slate-300">
    <span>
        <span class="font-medium">Active</span>
        <span class="block text-xs text-slate-500">
            Active patches are pre-selected on deployments touching their repository, so they are not silently dropped
            on a later deploy.
        </span>
    </span>
</label>
