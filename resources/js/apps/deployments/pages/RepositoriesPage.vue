<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';

import { ApiError, api, endpoint } from '../../../api';
import AppButton from '../../../components/AppButton.vue';
import CardPanel from '../../../components/CardPanel.vue';
import LoadState from '../../../components/LoadState.vue';
import PaginationBar from '../../../components/PaginationBar.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
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

/** Whether an empty result is "nothing registered" or "nothing matches". */
const filtered = computed(() =>
    Object.values(filters.value).some((value) => value !== '' && value !== false),
);
const types = computed(() => session.reference.repository_types ?? []);
</script>

<template>
    <div class="space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Repositories</h1>
                <p class="mt-1.5 max-w-prose text-sm text-pretty text-fg-muted">
                    Core, every extension and every skin this console can deploy, each pinned to a ref per core
                    version.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <AppButton to="/deployments/repositories/config" variant="ghost">Config repository</AppButton>
                <AppButton v-if="can('manage_repositories')" to="/deployments/import" variant="ghost">
                    Import from disk
                </AppButton>
                <AppButton
                    v-if="can('manage_repositories')"
                    to="/deployments/repositories/new"
                    variant="primary"
                    icon="plus"
                >
                    Register a repository
                </AppButton>
            </div>
        </header>

        <div class="flex flex-wrap items-end gap-3">
            <input
                v-model="filters.q"
                type="search"
                placeholder="Search by name…"
                class="w-64 input-control"
            />

            <select
                v-model="filters.type"
                class="input-control"
            >
                <option value="">All types</option>
                <option v-for="type in types" :key="type.value" :value="type.value">{{ type.plural_label }}</option>
            </select>

            <select
                v-model="filters.version"
                class="input-control"
            >
                <option value="">Any version</option>
                <option v-for="version in data?.versions ?? []" :key="version.id" :value="version.id">
                    {{ version.version }}
                </option>
            </select>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="filters.in_use" type="checkbox" class="size-4 rounded border-line-strong" />
                In use by the farm
            </label>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="filters.imported" type="checkbox" class="size-4 rounded border-line-strong" />
                Imported from disk
            </label>
        </div>

        <LoadState
            :loading="loading"
            :error="error"
            :empty="repositories.length === 0"
            :empty-title="filtered ? 'No repositories match these filters' : 'No repositories are registered'"
            :empty-message="
                filtered
                    ? 'Nothing in the registry matches. Clearing the filters shows everything this console knows about.'
                    : 'The registry is what this console deploys from: core, every extension and every skin, each pinned to a ref. If the farm already has MediaWiki on disk, reading the tree fills it in for you.'
            "
            :skeleton-rows="6"
            @retry="load"
        >
            <CardPanel flush>
                <table class="w-full text-sm">
                    <thead class="label-caps border-b border-line text-start">
                        <tr>
                            <th class="px-5 py-2">Name</th>
                            <th class="px-5 py-2">Type</th>
                            <th class="px-5 py-2">Checkouts</th>
                            <th class="px-5 py-2">Remote</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="repository in repositories" :key="repository.id" class="align-top">
                            <td class="px-5 py-2">
                                <RouterLink :to="`/deployments/repositories/${repository.id}`" class="link font-medium">
                                    {{ repository.name }}
                                </RouterLink>
                                <span v-if="repository.manifest_name" class="block text-xs text-fg-subtle">
                                    “{{ repository.manifest_name }}”
                                </span>
                                <span class="mt-0.5 flex flex-wrap gap-x-2 text-xs">
                                    <span v-if="repository.in_use" class="text-success-text">in use</span>
                                    <span v-if="repository.imported" class="text-fg-subtle">imported</span>
                                    <span v-if="repository.scoped" class="text-accent-text">permission-scoped</span>
                                </span>
                            </td>
                            <td class="px-5 py-2 text-fg-muted">{{ repository.type_label }}</td>
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
                                            :tone="checkout.status_tone"
                                        />
                                        <code class="font-mono text-xs text-fg-subtle">
                                            {{ checkout.resolved_ref ?? 'floating' }}
                                        </code>
                                        <span
                                            v-if="checkout.has_ref_drift"
                                            class="text-xs text-warning-text"
                                            :title="`On disk: ${checkout.observed_ref}`"
                                        >
                                            → on disk {{ shortRef(checkout.observed_ref) }}
                                        </span>
                                    </li>
                                    <li v-if="(repository.checkouts ?? []).length === 0" class="text-xs text-fg-subtle">
                                        none
                                    </li>
                                </ul>
                            </td>
                            <td class="px-5 py-2">
                                <code class="font-mono text-xs break-all text-fg-subtle">{{ repository.git_url }}</code>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardPanel>

            <PaginationBar :meta="data.meta" unit="repository" @go="page = $event" />
        </LoadState>
    </div>
</template>
