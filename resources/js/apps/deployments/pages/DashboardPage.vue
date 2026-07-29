<script setup>
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import { ApiError, api, endpoint } from '../../../api';
import CardPanel from '../../../components/CardPanel.vue';
import LoadState from '../../../components/LoadState.vue';
import StatusBadge from '../components/StatusBadge.vue';
import StepList from '../components/StepList.vue';
import { duration, relative } from '../../../format';
import { usePolling } from '../../../live';
import { can, session } from '../../../store';

/**
 * What is running now, what ran recently, and whether the registry actually
 * describes the farm.
 *
 * Polled rather than pushed: this screen is a summary of every deployment, and one
 * request every few seconds is simpler than reconciling broadcasts from several at
 * once. The per-deployment screen is the one that goes live over Echo.
 */
const data = ref(null);
const loading = ref(true);
const error = ref(null);

const load = async () => {
    try {
        data.value = await api.get(endpoint('dashboard'));
        error.value = null;
    } catch (thrown) {
        if (thrown instanceof ApiError) {
            error.value = thrown;
        }
    } finally {
        loading.value = false;
    }

    // Stop polling once nothing is in flight; the next visit reloads anyway.
    return (data.value?.active?.length ?? 0) > 0;
};

const { start } = usePolling(load, { interval: 5000 });

onMounted(start);
</script>

<template>
    <LoadState :loading="loading" :error="error" @retry="load">
        <div v-if="data" class="space-y-6">
            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs tracking-wide text-slate-500 uppercase">Repositories</p>
                    <p class="mt-1 text-2xl font-semibold">{{ data.registry.repositories }}</p>
                    <p class="text-xs text-slate-500">{{ data.registry.checkouts }} checkouts on disk</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs tracking-wide text-slate-500 uppercase">Core versions</p>
                    <p class="mt-1 text-2xl font-semibold">{{ data.versions.length }}</p>
                    <p class="text-xs text-slate-500">
                        {{ data.versions.map((version) => version.version).join(', ') || 'none registered' }}
                    </p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs tracking-wide text-slate-500 uppercase">Fleet</p>
                    <p class="mt-1 text-2xl font-semibold">{{ data.registry.appservers }}</p>
                    <p class="text-xs text-slate-500">
                        appservers, {{ data.registry.proxies }} proxies
                    </p>
                </div>
                <div
                    class="rounded-lg border bg-white p-4 shadow-sm"
                    :class="data.registry.drifted_checkouts > 0 ? 'border-amber-300' : 'border-slate-200'"
                >
                    <p class="text-xs tracking-wide text-slate-500 uppercase">Drift</p>
                    <p
                        class="mt-1 text-2xl font-semibold"
                        :class="data.registry.drifted_checkouts > 0 ? 'text-amber-700' : ''"
                    >
                        {{ data.registry.drifted_checkouts }}
                    </p>
                    <p class="text-xs text-slate-500">
                        checkouts whose disk ref differs from their pin
                    </p>
                </div>
            </section>

            <!-- A farm with no config repository registered is a farm this app
                 cannot deploy wiki config for, which is worth saying once rather
                 than leaving to be discovered in the wizard. -->
            <div
                v-if="!data.registry.has_config_repository && can('manage_repositories')"
                class="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900"
            >
                <p class="font-medium">No config repository is registered.</p>
                <p class="mt-1 text-xs">
                    Wiki config lives outside the version trees, at
                    <code class="font-mono">{{ session.settings.config_dir }}</code>.
                    <RouterLink to="/deployments/repositories/config" class="font-medium underline">Add it</RouterLink> —
                    it takes the git URL and nothing else.
                </p>
            </div>

            <CardPanel
                :title="`Running now (${data.active.length})`"
                subtitle="Live from the queue worker. Steps update over the websocket, with a poll behind it."
            >
                <p v-if="data.active.length === 0" class="text-sm text-slate-500">
                    Nothing is deploying. The fleet is quiet.
                </p>

                <div v-else class="space-y-6">
                    <article v-for="deployment in data.active" :key="deployment.id">
                        <header class="flex flex-wrap items-center gap-2">
                            <RouterLink
                                :to="`/deployments/${deployment.id}`"
                                class="font-medium underline decoration-slate-300"
                            >
                                #{{ deployment.id }}
                            </RouterLink>
                            <StatusBadge :label="deployment.status_label" :classes="deployment.status_classes" />
                            <StatusBadge :label="deployment.intent_label" :classes="deployment.intent_classes" />
                            <span class="text-sm text-slate-600">{{ deployment.summary }}</span>
                            <span class="ml-auto text-xs text-slate-500">
                                {{ deployment.creator }} · {{ duration(deployment.duration) }}
                            </span>
                        </header>

                        <div
                            v-if="deployment.awaiting_decision"
                            class="mt-2 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                        >
                            <p class="font-medium">{{ deployment.pending_decision_label }} — waiting for a decision.</p>
                            <RouterLink :to="`/deployments/${deployment.id}`" class="text-xs font-medium underline">
                                Answer it
                            </RouterLink>
                        </div>

                        <div class="mt-3">
                            <StepList
                                :steps-by-host="deployment.steps_by_host ?? {}"
                                :staging-host="deployment.staging_host"
                            />
                        </div>
                    </article>
                </div>
            </CardPanel>

            <CardPanel title="Recent deployments" flush>
                <template #actions>
                    <RouterLink to="/deployments/history" class="text-slate-600 underline hover:text-slate-900">
                        Full history
                    </RouterLink>
                </template>

                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 text-left text-xs tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th class="px-5 py-2">#</th>
                            <th class="px-5 py-2">Status</th>
                            <th class="px-5 py-2">What</th>
                            <th class="px-5 py-2">By</th>
                            <th class="px-5 py-2">When</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="deployment in data.recent" :key="deployment.id">
                            <td class="px-5 py-2">
                                <RouterLink :to="`/deployments/${deployment.id}`" class="underline">
                                    {{ deployment.id }}
                                </RouterLink>
                            </td>
                            <td class="px-5 py-2">
                                <StatusBadge :label="deployment.status_label" :classes="deployment.status_classes" />
                            </td>
                            <td class="px-5 py-2">{{ deployment.summary }}</td>
                            <td class="px-5 py-2 text-slate-600">{{ deployment.creator }}</td>
                            <td class="px-5 py-2 text-slate-500">{{ relative(deployment.created_at) }}</td>
                        </tr>
                        <tr v-if="data.recent.length === 0">
                            <td colspan="5" class="px-5 py-4 text-slate-500">No deployments yet.</td>
                        </tr>
                    </tbody>
                </table>
            </CardPanel>
        </div>
    </LoadState>
</template>
