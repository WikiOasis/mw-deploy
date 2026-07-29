<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';

import { ApiError, api, endpoint } from '../../../api';
import CardPanel from '../../../components/CardPanel.vue';
import LoadState from '../../../components/LoadState.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { shortRef } from '../../../format';
import { can, session } from '../../../store';

/**
 * The repository registry. Read-only for anyone with an account; the manage links
 * appear for repositories.manage.
 */
const data = ref(null);
const loading = ref(true);
const error = ref(null);

const filters = ref({ q: '', type: '', version: '', in_use: false, imported: false });
const page = ref(1);

let debounce = null;

const load = async () => {
    loading.value = true;

    try {
        data.value = await api.get(endpoint('repositories'), {
            params: {
                q: filters.value.q,
                type: filters.value.type,
                version: filters.value.version,
                in_use: filters.value.in_use ? 1 : '',
                imported: filters.value.imported ? 1 : '',
                page: page.value,
            },
        });
        error.value = null;
    } catch (thrown) {
        if (thrown instanceof ApiError) {
            error.value = thrown;
        }
    } finally {
        loading.value = false;
    }
};

watch(
    filters,
    () => {
        page.value = 1;
        window.clearTimeout(debounce);
        debounce = window.setTimeout(load, 250);
    },
    { deep: true },
);

watch(page, load);

onMounted(load);

const repositories = computed(() => data.value?.data ?? []);
const types = computed(() => session.reference.repository_types ?? []);
</script>

<template>
    <div class="space-y-4">
        <header class="flex flex-wrap items-center gap-3">
            <h1 class="text-lg font-semibold tracking-tight">Repositories</h1>

            <div class="ml-auto flex items-center gap-2 text-sm">
                <RouterLink to="/deployments/repositories/config" class="text-slate-600 underline hover:text-slate-900">
                    Config repository
                </RouterLink>
                <RouterLink v-if="can('manage_repositories')" to="/deployments/import" class="text-slate-600 underline hover:text-slate-900">
                    Import from disk
                </RouterLink>
                <RouterLink
                    v-if="can('manage_repositories')"
                    to="/deployments/repositories/new"
                    class="rounded-md bg-slate-900 px-3 py-1.5 font-medium text-white hover:bg-slate-700"
                >
                    Register a repository
                </RouterLink>
            </div>
        </header>

        <div class="flex flex-wrap items-end gap-3">
            <input
                v-model="filters.q"
                type="search"
                placeholder="Search by name…"
                class="w-64 rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
            />

            <select
                v-model="filters.type"
                class="rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
            >
                <option value="">All types</option>
                <option v-for="type in types" :key="type.value" :value="type.value">{{ type.plural_label }}</option>
            </select>

            <select
                v-model="filters.version"
                class="rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
            >
                <option value="">Any version</option>
                <option v-for="version in data?.versions ?? []" :key="version.id" :value="version.id">
                    {{ version.version }}
                </option>
            </select>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="filters.in_use" type="checkbox" class="rounded border-slate-300" />
                In use by the farm
            </label>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="filters.imported" type="checkbox" class="rounded border-slate-300" />
                Imported from disk
            </label>
        </div>

        <LoadState
            :loading="loading"
            :error="error"
            :empty="repositories.length === 0"
            empty-message="No repositories match. If the farm already has MediaWiki on disk, import it rather than registering by hand."
            @retry="load"
        >
            <CardPanel flush>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 text-left text-xs tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th class="px-5 py-2">Name</th>
                            <th class="px-5 py-2">Type</th>
                            <th class="px-5 py-2">Checkouts</th>
                            <th class="px-5 py-2">Remote</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="repository in repositories" :key="repository.id" class="align-top">
                            <td class="px-5 py-2">
                                <RouterLink :to="`/deployments/repositories/${repository.id}`" class="font-medium underline">
                                    {{ repository.name }}
                                </RouterLink>
                                <span v-if="repository.manifest_name" class="block text-xs text-slate-400">
                                    “{{ repository.manifest_name }}”
                                </span>
                                <span class="mt-0.5 flex flex-wrap gap-x-2 text-xs">
                                    <span v-if="repository.in_use" class="text-emerald-700">in use</span>
                                    <span v-if="repository.imported" class="text-slate-500">imported</span>
                                    <span v-if="repository.scoped" class="text-violet-700">permission-scoped</span>
                                </span>
                            </td>
                            <td class="px-5 py-2 text-slate-600">{{ repository.type_label }}</td>
                            <td class="px-5 py-2">
                                <ul class="space-y-0.5">
                                    <li
                                        v-for="checkout in repository.checkouts ?? []"
                                        :key="checkout.id"
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <span class="font-medium">{{ checkout.version_label }}</span>
                                        <StatusBadge
                                            :label="checkout.status_label"
                                            :classes="checkout.status_classes"
                                        />
                                        <code class="font-mono text-xs text-slate-500">
                                            {{ checkout.resolved_ref ?? 'floating' }}
                                        </code>
                                        <span
                                            v-if="checkout.has_ref_drift"
                                            class="text-xs text-amber-700"
                                            :title="`On disk: ${checkout.observed_ref}`"
                                        >
                                            → on disk {{ shortRef(checkout.observed_ref) }}
                                        </span>
                                    </li>
                                    <li v-if="(repository.checkouts ?? []).length === 0" class="text-xs text-slate-400">
                                        none
                                    </li>
                                </ul>
                            </td>
                            <td class="px-5 py-2">
                                <code class="font-mono text-xs break-all text-slate-500">{{ repository.git_url }}</code>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardPanel>

            <div v-if="data.meta.last_page > 1" class="flex items-center justify-between text-sm">
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 ring-1 ring-slate-300 disabled:opacity-40"
                    :disabled="data.meta.current_page <= 1"
                    @click="page = data.meta.current_page - 1"
                >
                    Previous
                </button>
                <span class="text-slate-500">
                    Page {{ data.meta.current_page }} of {{ data.meta.last_page }} · {{ data.meta.total }} total
                </span>
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 ring-1 ring-slate-300 disabled:opacity-40"
                    :disabled="data.meta.current_page >= data.meta.last_page"
                    @click="page = data.meta.current_page + 1"
                >
                    Next
                </button>
            </div>
        </LoadState>
    </div>
</template>
