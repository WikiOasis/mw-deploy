<script setup>
import { computed, reactive, ref } from 'vue';

import { api, endpoint } from '../api';
import { relative, shortRef } from '../format';
import SearchableCombobox from './SearchableCombobox.vue';
import StatusBadge from './StatusBadge.vue';

/**
 * The checkout picker, shared by the deploy and undeploy wizards.
 *
 * The unit of selection is a *checkout* — one repository in one core version — so
 * "all versions" is a bulk toggle over a repository's rows rather than a mode.
 * Each row carries its own ref, which is what lets 1.45 go to REL1_45 and 1.46 to
 * REL1_46 in a single submission.
 */
const props = defineProps({
    repositories: { type: Array, required: true },
    types: { type: Array, required: true },
    intent: { type: String, default: 'deploy' },
    /** { [checkoutId]: { refType, refValue } } */
    modelValue: { type: Object, required: true },
});

const emit = defineEmits(['update:modelValue']);

const isUndeploy = computed(() => props.intent === 'undeploy');
const filter = ref('');

/** Lazily loaded branches/commits/fetch metadata, per checkout. */
const commits = reactive({});
const branches = reactive({});
const fetchedAt = reactive({});
const loadingCommits = reactive({});
const loadingBranches = reactive({});
const fetching = reactive({});

const byType = computed(() =>
    props.types
        .map((type) => ({
            ...type,
            repositories: props.repositories.filter((repository) => repository.type === type.value),
        }))
        .filter((group) => group.repositories.length > 0),
);

const matches = (repository) => {
    const needle = filter.value.trim().toLowerCase();

    if (needle === '') {
        return true;
    }

    return (
        repository.name.toLowerCase().includes(needle) ||
        (repository.manifest_name ?? '').toLowerCase().includes(needle)
    );
};

const selection = computed(() => props.modelValue);

const isSelected = (id) => Object.prototype.hasOwnProperty.call(selection.value, id);

const update = (next) => emit('update:modelValue', next);

/**
 * Selecting a checkout pre-fills its own pin, which is the answer wanted most of
 * the time — the version model exists precisely so nobody retypes REL1_45.
 */
const select = (checkout) => {
    update({
        ...selection.value,
        [checkout.id]: {
            refType: checkout.tracked_ref_type === 'commit' ? 'commit' : 'branch',
            refValue: isUndeploy.value ? null : (checkout.resolved_ref ?? ''),
        },
    });

    if (!isUndeploy.value && branches[checkout.id] === undefined) {
        loadBranches(checkout);
    }
};

const deselect = (checkout) => {
    const next = { ...selection.value };

    delete next[checkout.id];
    update(next);
};

const toggle = (checkout) => (isSelected(checkout.id) ? deselect(checkout) : select(checkout));

const repositoryState = (repository) => {
    const selectedCount = repository.checkouts.filter((checkout) => isSelected(checkout.id)).length;

    if (selectedCount === 0) {
        return 'none';
    }

    return selectedCount === repository.checkouts.length ? 'all' : 'some';
};

const toggleRepository = (repository) => {
    const next = { ...selection.value };
    const selectAll = repositoryState(repository) !== 'all';

    repository.checkouts.forEach((checkout) => {
        if (selectAll) {
            next[checkout.id] = {
                refType: checkout.tracked_ref_type === 'commit' ? 'commit' : 'branch',
                refValue: isUndeploy.value ? null : (checkout.resolved_ref ?? ''),
            };
        } else {
            delete next[checkout.id];
        }
    });

    update(next);
};

const setRefType = async (checkout, refType) => {
    const current = selection.value[checkout.id];

    update({
        ...selection.value,
        [checkout.id]: {
            refType,
            // Switching branch → commit clears the branch name: submitting
            // "REL1_45" as a commit would be recorded as a ref that does not exist.
            refValue: refType === 'branch' ? (checkout.resolved_ref ?? '') : '',
        },
    });

    if (refType === 'commit' && commits[checkout.id] === undefined) {
        await loadCommits(checkout, current?.refValue || checkout.resolved_ref);
    } else if (refType === 'branch' && branches[checkout.id] === undefined) {
        await loadBranches(checkout);
    }
};

const setRefValue = (checkout, refValue) => {
    update({
        ...selection.value,
        [checkout.id]: { ...selection.value[checkout.id], refValue },
    });
};

/**
 * Commits are fetched per checkout, on demand. With a hundred checkouts on the
 * page, listing them eagerly would be a hundred git calls before the operator has
 * even chosen anything.
 */
const loadCommits = async (checkout, branch) => {
    loadingCommits[checkout.id] = true;

    try {
        const payload = await api.get(endpoint(`checkouts/${checkout.id}/refs`), {
            params: branch ? { branch } : {},
        });

        commits[checkout.id] = payload.commits ?? [];
        fetchedAt[checkout.id] = payload.fetched_at ?? fetchedAt[checkout.id] ?? null;
    } catch {
        // Ref discovery is a convenience: the free-text field still works, so a
        // failed listing degrades to typing the SHA rather than blocking.
        commits[checkout.id] = [];
    } finally {
        loadingCommits[checkout.id] = false;
    }
};

const loadBranches = async (checkout) => {
    loadingBranches[checkout.id] = true;

    try {
        const payload = await api.get(endpoint(`checkouts/${checkout.id}/refs`));

        branches[checkout.id] = payload.branches ?? [];
        fetchedAt[checkout.id] = payload.fetched_at ?? fetchedAt[checkout.id] ?? null;
    } catch {
        branches[checkout.id] = [];
    } finally {
        loadingBranches[checkout.id] = false;
    }
};

/**
 * Bypasses the persistent ref cache server-side (see CachedGitRefProvider) and
 * refreshes whichever list is on screen in place.
 */
const fetchLatest = async (checkout) => {
    fetching[checkout.id] = true;

    try {
        const branch = selection.value[checkout.id]?.refType === 'branch' ? selection.value[checkout.id].refValue : null;
        const payload = await api.post(endpoint(`checkouts/${checkout.id}/refs/fetch`), {}, {
            params: branch ? { branch } : {},
        });

        branches[checkout.id] = payload.branches ?? [];
        commits[checkout.id] = payload.commits ?? [];
        fetchedAt[checkout.id] = payload.fetched_at ?? null;
    } catch {
        // "Fetch latest" is a convenience on top of the cache; leaving the
        // existing list in place beats clearing it out on a transient failure.
    } finally {
        fetching[checkout.id] = false;
    }
};

const selectedCountFor = (repository) =>
    repository.checkouts.filter((checkout) => isSelected(checkout.id)).length;
</script>

<template>
    <div>
        <div v-if="repositories.length === 0" class="text-sm text-slate-500">
            <template v-if="isUndeploy">
                There is nothing you have permission to remove. Removal is a separate grant from deployment —
                ask an administrator for <code class="font-mono">deploy.undeploy_extension</code> or
                <code class="font-mono">deploy.undeploy_skin</code>.
            </template>
            <template v-else>
                You do not have permission to deploy any registered repository, or nothing is registered yet.
            </template>
        </div>

        <div v-else class="mb-4">
            <input
                v-model="filter"
                type="search"
                placeholder="Filter by name…"
                class="block w-full max-w-sm rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-slate-900 focus:outline-none"
            />
        </div>

        <section v-for="group in byType" :key="group.value" class="mb-6 last:mb-0">
            <h3 class="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">
                {{ group.plural_label }}
            </h3>

            <ul class="divide-y divide-slate-100 rounded-md border border-slate-200">
                <template v-for="repository in group.repositories" :key="repository.id">
                    <li v-if="matches(repository)" class="p-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="rounded border px-2 py-0.5 text-xs font-medium"
                                :class="{
                                    'border-slate-900 bg-slate-900 text-white': repositoryState(repository) === 'all',
                                    'border-slate-400 bg-slate-100 text-slate-700': repositoryState(repository) === 'some',
                                    'border-slate-300 text-slate-600': repositoryState(repository) === 'none',
                                }"
                                @click="toggleRepository(repository)"
                            >
                                All versions{{ repositoryState(repository) === 'all' ? ' ✓' : '' }}
                            </button>

                            <span class="text-sm font-medium">{{ repository.name }}</span>
                            <span v-if="repository.manifest_name" class="text-xs text-slate-400">
                                “{{ repository.manifest_name }}”
                            </span>
                            <span class="text-xs text-slate-500">
                                {{ repository.checkouts.length }} version(s)
                            </span>
                            <span
                                v-if="selectedCountFor(repository) > 0"
                                class="ml-auto text-xs text-slate-500"
                            >
                                {{ selectedCountFor(repository) }} selected
                            </span>
                        </div>

                        <ul class="mt-2 space-y-2 pl-2">
                            <li
                                v-for="checkout in repository.checkouts"
                                :key="checkout.id"
                                class="rounded border border-slate-100 p-2"
                                :class="isSelected(checkout.id) ? 'bg-slate-50' : ''"
                            >
                                <label class="flex flex-wrap items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        class="rounded border-slate-300"
                                        :checked="isSelected(checkout.id)"
                                        @change="toggle(checkout)"
                                    />
                                    <span class="font-medium">{{ checkout.version_label }}</span>
                                    <code class="font-mono text-xs text-slate-500">{{ checkout.path }}</code>
                                    <StatusBadge :label="checkout.status_label" :classes="checkout.status_classes" />
                                    <span v-if="!isUndeploy" class="text-xs text-slate-500">
                                        {{ checkout.ref_mode_summary }}
                                    </span>
                                </label>

                                <!-- Drift is worth saying out loud here rather than only on the
                                     repository screen: deploying this checkout is what would
                                     move the tree off the ref it is currently on. -->
                                <p
                                    v-if="!isUndeploy && checkout.has_ref_drift"
                                    class="mt-1 pl-6 text-xs text-amber-700"
                                >
                                    Staging is on <code class="font-mono">{{ shortRef(checkout.observed_ref) }}</code>,
                                    not the pinned <code class="font-mono">{{ checkout.resolved_ref }}</code>.
                                </p>

                                <div
                                    v-if="isSelected(checkout.id) && !isUndeploy"
                                    class="mt-2 flex flex-wrap items-end gap-3 pl-6"
                                >
                                    <div class="flex rounded-md border border-slate-300 text-xs">
                                        <button
                                            type="button"
                                            class="px-2 py-1"
                                            :class="
                                                selection[checkout.id].refType === 'branch'
                                                    ? 'bg-slate-900 text-white'
                                                    : 'text-slate-600'
                                            "
                                            @click="setRefType(checkout, 'branch')"
                                        >
                                            Branch
                                        </button>
                                        <button
                                            type="button"
                                            class="px-2 py-1"
                                            :class="
                                                selection[checkout.id].refType === 'commit'
                                                    ? 'bg-slate-900 text-white'
                                                    : 'text-slate-600'
                                            "
                                            @click="setRefType(checkout, 'commit')"
                                        >
                                            Commit
                                        </button>
                                    </div>

                                    <div v-if="selection[checkout.id].refType === 'branch'" class="w-72">
                                        <SearchableCombobox
                                            :model-value="selection[checkout.id].refValue"
                                            :options="branches[checkout.id] ?? []"
                                            :loading="loadingBranches[checkout.id]"
                                            :placeholder="checkout.resolved_ref ?? 'branch name'"
                                            empty-label="No matching branch — free text is still accepted"
                                            @update:model-value="(value) => setRefValue(checkout, value)"
                                        />
                                    </div>

                                    <div v-else class="w-96">
                                        <SearchableCombobox
                                            :model-value="selection[checkout.id].refValue"
                                            :options="(commits[checkout.id] ?? []).map((commit) => ({ value: commit.value, label: commit.label }))"
                                            :loading="loadingCommits[checkout.id]"
                                            placeholder="Search recent commits, or paste a SHA"
                                            empty-label="No matching commit — a pasted SHA is still accepted"
                                            @update:model-value="(value) => setRefValue(checkout, value)"
                                        />
                                    </div>

                                    <div class="flex items-center gap-2 text-xs text-slate-500">
                                        <button
                                            type="button"
                                            class="rounded border border-slate-300 px-2 py-1 font-medium text-slate-700 disabled:opacity-50"
                                            :disabled="fetching[checkout.id]"
                                            @click="fetchLatest(checkout)"
                                        >
                                            {{ fetching[checkout.id] ? 'Fetching…' : 'Fetch latest' }}
                                        </button>
                                        <span v-if="fetchedAt[checkout.id]">fetched {{ relative(fetchedAt[checkout.id]) }}</span>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </li>
                </template>
            </ul>
        </section>
    </div>
</template>
