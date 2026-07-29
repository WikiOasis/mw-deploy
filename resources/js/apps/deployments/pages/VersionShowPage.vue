<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import { ApiError, api, endpoint } from '../../../api';
import AppButton from '../../../components/AppButton.vue';
import CardPanel from '../../../components/CardPanel.vue';
import LoadState from '../../../components/LoadState.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
import { pluralise, relative, shortRef } from '../../../format';

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
            <header>
                <AppButton to="/deployments/versions" variant="ghost" icon="arrow-left" class="-ms-3 mb-2">
                    Core versions
                </AppButton>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <h1 class="numeric text-xl font-semibold">{{ version.version }}</h1>
                    <StatusBadge :label="version.status_label" :tone="version.status_tone" />
                </div>
                <code class="mt-1.5 block font-mono text-xs break-all text-fg-subtle">
                    {{ version.staging_path }}
                </code>
            </header>

            <div class="panel grid divide-line sm:grid-cols-3 sm:divide-x">
                <div class="p-5">
                    <p class="label-caps">Checkouts on disk</p>
                    <p class="numeric mt-1.5 text-2xl font-semibold">{{ version.checkout_counts?.total ?? 0 }}</p>
                </div>
                <div class="border-t border-line p-5 sm:border-t-0">
                    <p class="label-caps">MW_VERSION</p>
                    <p class="numeric mt-1.5 text-2xl font-semibold">{{ version.core_version ?? '—' }}</p>
                    <p class="text-xs text-fg-subtle">as read from includes/Defines.php</p>
                </div>
                <div class="panel p-5">
                    <p class="label-caps">Origin</p>
                    <p class="mt-1 text-sm">
                        {{ version.imported ? 'Imported from disk' : `Cut from ${version.created_from ?? 'nothing'}` }}
                    </p>
                    <p class="text-xs text-fg-subtle">{{ version.creator ?? 'unknown' }}</p>
                </div>
            </div>

            <CardPanel
                v-for="(checkouts, type) in byType"
                :key="type"
                :title="typeLabels[type] ?? type"
                :subtitle="pluralise(checkouts.length, 'checkout')"
                flush
            >
                <table class="w-full text-sm">
                    <thead class="label-caps border-b border-line text-start">
                        <tr>
                            <th class="px-5 py-2">Repository</th>
                            <th class="px-5 py-2">Status</th>
                            <th class="px-5 py-2">Pin</th>
                            <th class="px-5 py-2">On disk</th>
                            <th class="px-5 py-2">Path</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="checkout in checkouts" :key="checkout.id">
                            <td class="px-5 py-2">
                                <RouterLink :to="`/deployments/repositories/${checkout.repository_id}`" class="link">
                                    {{ checkout.repository_name }}
                                </RouterLink>
                            </td>
                            <td class="px-5 py-2">
                                <StatusBadge :label="checkout.status_label" :tone="checkout.status_tone" />
                            </td>
                            <td class="px-5 py-2 font-mono text-xs">{{ checkout.resolved_ref ?? '—' }}</td>
                            <td class="px-5 py-2 font-mono text-xs">
                                <span :class="checkout.has_ref_drift ? 'text-warning-text' : ''">
                                    {{ shortRef(checkout.observed_ref) }}
                                </span>
                                <span v-if="checkout.observed_at" class="block text-fg-subtle">
                                    {{ relative(checkout.observed_at) }}
                                </span>
                            </td>
                            <td class="px-5 py-2 font-mono text-xs text-fg-subtle">{{ checkout.path }}</td>
                        </tr>
                    </tbody>
                </table>
            </CardPanel>

            <CardPanel title="Deployments for this version" flush>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-line">
                        <tr v-for="deployment in data.deployments" :key="deployment.id">
                            <td class="px-5 py-2">
                                <RouterLink :to="`/deployments/${deployment.id}`" class="link">
                                    #{{ deployment.id }}
                                </RouterLink>
                            </td>
                            <td class="px-5 py-2">
                                <StatusBadge :label="deployment.status_label" :tone="deployment.status_tone" />
                            </td>
                            <td class="px-5 py-2">{{ deployment.summary }}</td>
                            <td class="px-5 py-2 text-fg-subtle">{{ relative(deployment.created_at) }}</td>
                        </tr>
                        <tr v-if="data.deployments.length === 0">
                            <td class="px-5 py-4 text-fg-subtle">Nothing has been deployed for this version yet.</td>
                        </tr>
                    </tbody>
                </table>
            </CardPanel>
        </div>
    </LoadState>
</template>
