<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import { ApiError, api, endpoint } from '../../../api';
import CardPanel from '../../../components/CardPanel.vue';
import LoadState from '../../../components/LoadState.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { relative, shortRef } from '../../../format';

/**
 * One core version and everything checked out inside it.
 */
const props = defineProps({
    id: { type: [String, Number], required: true },
});

const data = ref(null);
const loading = ref(true);
const error = ref(null);

const load = async () => {
    loading.value = true;

    try {
        data.value = await api.get(endpoint(`versions/${props.id}`));
        error.value = null;
    } catch (thrown) {
        if (thrown instanceof ApiError) {
            error.value = thrown;
        }
    } finally {
        loading.value = false;
    }
};

onMounted(load);

const version = computed(() => data.value?.data ?? null);

const byType = computed(() => {
    const grouped = {};

    (version.value?.checkouts ?? []).forEach((checkout) => {
        (grouped[checkout.repository_type ?? 'unknown'] ??= []).push(checkout);
    });

    return grouped;
});

const typeLabels = {
    core: 'MediaWiki core',
    extension: 'Extensions',
    skin: 'Skins',
    config: 'Config',
    unknown: 'Unknown',
};
</script>

<template>
    <LoadState :loading="loading" :error="error" @retry="load">
        <div v-if="version" class="space-y-6">
            <header class="flex flex-wrap items-center gap-3">
                <RouterLink to="/deployments/versions" class="text-sm text-slate-500 underline">Versions</RouterLink>
                <h1 class="text-lg font-semibold tracking-tight">{{ version.version }}</h1>
                <StatusBadge :label="version.status_label" :classes="version.status_classes" />
                <code class="font-mono text-xs text-slate-500">{{ version.staging_path }}</code>
            </header>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs tracking-wide text-slate-500 uppercase">Checkouts on disk</p>
                    <p class="mt-1 text-2xl font-semibold">{{ version.checkout_counts?.total ?? 0 }}</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs tracking-wide text-slate-500 uppercase">MW_VERSION</p>
                    <p class="mt-1 text-2xl font-semibold">{{ version.core_version ?? '—' }}</p>
                    <p class="text-xs text-slate-500">as read from includes/Defines.php</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs tracking-wide text-slate-500 uppercase">Origin</p>
                    <p class="mt-1 text-sm">
                        {{ version.imported ? 'Imported from disk' : `Cut from ${version.created_from ?? 'nothing'}` }}
                    </p>
                    <p class="text-xs text-slate-500">{{ version.creator ?? 'unknown' }}</p>
                </div>
            </div>

            <CardPanel
                v-for="(checkouts, type) in byType"
                :key="type"
                :title="typeLabels[type] ?? type"
                :subtitle="`${checkouts.length} checkout(s)`"
                flush
            >
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 text-left text-xs tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th class="px-5 py-2">Repository</th>
                            <th class="px-5 py-2">Status</th>
                            <th class="px-5 py-2">Pin</th>
                            <th class="px-5 py-2">On disk</th>
                            <th class="px-5 py-2">Path</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="checkout in checkouts" :key="checkout.id">
                            <td class="px-5 py-2">
                                <RouterLink :to="`/deployments/repositories/${checkout.repository_id}`" class="underline">
                                    {{ checkout.repository_name }}
                                </RouterLink>
                            </td>
                            <td class="px-5 py-2">
                                <StatusBadge :label="checkout.status_label" :classes="checkout.status_classes" />
                            </td>
                            <td class="px-5 py-2 font-mono text-xs">{{ checkout.resolved_ref ?? '—' }}</td>
                            <td class="px-5 py-2 font-mono text-xs">
                                <span :class="checkout.has_ref_drift ? 'text-amber-700' : ''">
                                    {{ shortRef(checkout.observed_ref) }}
                                </span>
                                <span v-if="checkout.observed_at" class="block text-slate-400">
                                    {{ relative(checkout.observed_at) }}
                                </span>
                            </td>
                            <td class="px-5 py-2 font-mono text-xs text-slate-500">{{ checkout.path }}</td>
                        </tr>
                    </tbody>
                </table>
            </CardPanel>

            <CardPanel title="Deployments for this version" flush>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="deployment in data.deployments" :key="deployment.id">
                            <td class="px-5 py-2">
                                <RouterLink :to="`/deployments/${deployment.id}`" class="underline">
                                    #{{ deployment.id }}
                                </RouterLink>
                            </td>
                            <td class="px-5 py-2">
                                <StatusBadge :label="deployment.status_label" :classes="deployment.status_classes" />
                            </td>
                            <td class="px-5 py-2">{{ deployment.summary }}</td>
                            <td class="px-5 py-2 text-slate-500">{{ relative(deployment.created_at) }}</td>
                        </tr>
                        <tr v-if="data.deployments.length === 0">
                            <td class="px-5 py-4 text-slate-500">Nothing has been deployed for this version yet.</td>
                        </tr>
                    </tbody>
                </table>
            </CardPanel>
        </div>
    </LoadState>
</template>
