<script setup>
import { computed, ref, watch } from 'vue';

import { ApiError, api, endpoint } from '../../../api';
import SearchableCombobox from '../../../components/SearchableCombobox.vue';

/**
 * Browse a checkout's tree at an arbitrary ref/commit, and read a file's
 * content there. Deliberately outside the deploy wizard: this is read-only
 * history exploration, not part of choosing what to deploy.
 *
 * A ref is resolved to its commit SHA once (server-side, see GitFileBrowser)
 * and every subsequent tree/blob lookup for the same session reuses it, so
 * typing a branch name and then walking ten directories deep does not
 * re-resolve the branch ten times.
 */
const props = defineProps({
    checkoutId: { type: [String, Number], required: true },
    branches: { type: Array, default: () => [] },
    defaultRef: { type: String, default: '' },
});

const ref_ = ref(props.defaultRef);
const sha = ref('');
const path = ref('');
const jumpTo = ref('');

const entries = ref([]);
const loadingTree = ref(false);
const treeError = ref(null);

const selectedFile = ref(null);
const blob = ref(null);
const loadingBlob = ref(false);
const blobError = ref(null);

const branchOptions = computed(() => props.branches.map((branch) => ({ value: branch.value, label: branch.label ?? branch.value })));

const crumbs = computed(() => {
    const parts = path.value.split('/').filter(Boolean);
    const acc = [];
    let running = '';

    for (const part of parts) {
        running = running === '' ? part : `${running}/${part}`;
        acc.push({ name: part, path: running });
    }

    return acc;
});

const loadTree = async (targetPath) => {
    if (ref_.value.trim() === '') {
        return;
    }

    loadingTree.value = true;
    treeError.value = null;
    selectedFile.value = null;
    blob.value = null;

    try {
        const payload = await api.get(endpoint(`checkouts/${props.checkoutId}/tree`), {
            params: { ref: ref_.value, path: targetPath },
        });

        sha.value = payload.sha;
        path.value = payload.path ?? '';
        entries.value = (payload.entries ?? []).slice().sort((a, b) => {
            if (a.type === b.type) {
                return a.name.localeCompare(b.name);
            }

            return a.type === 'tree' ? -1 : 1;
        });
    } catch (thrown) {
        entries.value = [];
        treeError.value = thrown instanceof ApiError ? thrown.message : 'Could not load the tree.';
    } finally {
        loadingTree.value = false;
    }
};

const openEntry = (entry) => {
    if (entry.type === 'tree') {
        loadTree(entry.path);

        return;
    }

    loadBlob(entry.path);
};

const loadBlob = async (filePath) => {
    selectedFile.value = filePath;
    loadingBlob.value = true;
    blobError.value = null;
    blob.value = null;

    try {
        blob.value = await api.get(endpoint(`checkouts/${props.checkoutId}/blob`), {
            params: { ref: ref_.value, path: filePath },
        });
    } catch (thrown) {
        blobError.value = thrown instanceof ApiError ? thrown.message : 'Could not read this file.';
    } finally {
        loadingBlob.value = false;
    }
};

const goToCrumb = (crumbPath) => loadTree(crumbPath);
const goToRoot = () => loadTree('');

const jump = () => {
    const target = jumpTo.value.trim().replace(/^\/+|\/+$/g, '');

    if (target === '') {
        return;
    }

    // Whether the typed path is a file or a directory is not known until the
    // server answers, so try it as a directory first and fall back to a blob.
    loadTree(target).then(() => {
        if (treeError.value) {
            loadBlob(target);
        }
    });
};

watch(
    () => props.checkoutId,
    () => {
        entries.value = [];
        selectedFile.value = null;
        blob.value = null;
        path.value = '';
    },
);
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <div class="w-64">
                <SearchableCombobox
                    v-model="ref_"
                    :options="branchOptions"
                    placeholder="Branch, tag or commit SHA"
                />
            </div>
            <button
                type="button"
                class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                :disabled="loadingTree || ref_.trim() === ''"
                @click="goToRoot"
            >
                Browse
            </button>
            <code v-if="sha" class="font-mono text-xs text-slate-500">{{ sha.slice(0, 12) }}</code>
        </div>

        <div v-if="sha" class="flex flex-wrap items-center gap-2">
            <div class="w-72">
                <input
                    v-model="jumpTo"
                    type="text"
                    placeholder="Jump to a path…"
                    class="block w-full rounded-md bg-white px-3 py-2 font-mono text-xs ring-1 ring-inset ring-slate-300"
                    @keydown.enter.prevent="jump"
                />
            </div>
            <button type="button" class="text-xs font-medium text-slate-600 underline" @click="jump">Go</button>
        </div>

        <nav v-if="sha" class="flex flex-wrap items-center gap-1 text-xs text-slate-500">
            <button type="button" class="underline hover:text-slate-900" @click="goToRoot">root</button>
            <template v-for="crumb in crumbs" :key="crumb.path">
                <span>/</span>
                <button type="button" class="underline hover:text-slate-900" @click="goToCrumb(crumb.path)">
                    {{ crumb.name }}
                </button>
            </template>
        </nav>

        <p v-if="treeError" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900">
            {{ treeError }}
        </p>

        <div v-if="sha" class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-md border border-slate-200">
                <p v-if="loadingTree" class="px-3 py-2 text-xs text-slate-500">Loading…</p>
                <ul v-else-if="entries.length === 0" class="px-3 py-2 text-xs text-slate-500">Empty directory.</ul>
                <ul v-else class="max-h-96 divide-y divide-slate-100 overflow-auto text-sm">
                    <li
                        v-for="entry in entries"
                        :key="entry.name"
                        class="cursor-pointer px-3 py-1.5 hover:bg-slate-50"
                        :class="selectedFile === entry.path ? 'bg-slate-100' : ''"
                        @click="openEntry(entry)"
                    >
                        <span class="mr-1">{{ entry.type === 'tree' ? '📁' : '📄' }}</span>
                        <span class="font-mono text-xs">{{ entry.name }}</span>
                        <span v-if="entry.size !== null && entry.type !== 'tree'" class="ml-2 text-xs text-slate-400">
                            {{ entry.size }} bytes
                        </span>
                    </li>
                </ul>
            </div>

            <div class="rounded-md border border-slate-200">
                <div v-if="!selectedFile" class="px-3 py-2 text-xs text-slate-500">Select a file to view it.</div>
                <div v-else-if="loadingBlob" class="px-3 py-2 text-xs text-slate-500">Loading…</div>
                <p v-else-if="blobError" class="px-3 py-2 text-xs text-rose-700">{{ blobError }}</p>
                <template v-else-if="blob">
                    <div class="flex items-center justify-between border-b border-slate-100 px-3 py-1.5 text-xs text-slate-500">
                        <code class="font-mono">{{ selectedFile }}</code>
                        <span>{{ blob.size }} bytes{{ blob.truncated ? ' (truncated)' : '' }}</span>
                    </div>
                    <p v-if="blob.binary" class="px-3 py-4 text-xs text-slate-500">
                        Binary file — not shown.
                    </p>
                    <pre v-else class="max-h-96 overflow-auto px-3 py-2 text-xs whitespace-pre-wrap">{{ blob.content }}</pre>
                </template>
            </div>
        </div>
    </div>
</template>
