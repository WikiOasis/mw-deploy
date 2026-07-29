<script setup>
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import { ApiError, api, endpoint } from '../../../api';
import AppButton from '../../../components/AppButton.vue';
import AppIcon from '../../../components/AppIcon.vue';
import CardPanel from '../../../components/CardPanel.vue';
import LoadState from '../../../components/LoadState.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
import StepList from '../components/StepList.vue';
import { duration, pluralise, relative } from '../../../format';
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
    <LoadState :loading="loading" :error="error" :skeleton-rows="5" @retry="load">
        <div v-if="data" class="space-y-6">
            <div>
                <h1 class="text-xl font-semibold">Overview</h1>
                <p class="mt-1.5 max-w-prose text-sm text-pretty text-fg-muted">
                    What the registry says the farm holds, what is deploying right now, and what ran recently.
                </p>
            </div>

            <!-- Four measures of the same registry, so they are one strip rather
                 than four cards: the divider carries the grouping and the eye is
                 not asked to cross four borders to compare two numbers. -->
            <section class="panel grid divide-line sm:grid-cols-2 sm:divide-x lg:grid-cols-4">
                <div class="p-5">
                    <p class="label-caps">Repositories</p>
                    <p class="numeric mt-1.5 text-2xl font-semibold">{{ data.registry.repositories }}</p>
                    <p class="mt-0.5 text-xs text-fg-subtle">
                        {{ pluralise(data.registry.checkouts, 'checkout') }} on disk
                    </p>
                </div>
                <div class="border-t border-line p-5 sm:border-t-0">
                    <p class="label-caps">Core versions</p>
                    <p class="numeric mt-1.5 text-2xl font-semibold">{{ data.versions.length }}</p>
                    <p class="mt-0.5 truncate text-xs text-fg-subtle">
                        {{ data.versions.map((version) => version.version).join(', ') || 'None registered' }}
                    </p>
                </div>
                <div class="border-t border-line p-5 lg:border-t-0">
                    <p class="label-caps">Fleet</p>
                    <p class="numeric mt-1.5 text-2xl font-semibold">{{ data.registry.appservers }}</p>
                    <p class="mt-0.5 text-xs text-fg-subtle">
                        appservers, {{ pluralise(data.registry.proxies, 'proxy', 'proxies') }}
                    </p>
                </div>
                <!-- Drift is the only one of the four that can be bad news, so it
                     is the only one that ever takes a colour — and it takes an icon
                     with it, because a number turning amber is not something you
                     can be relied on to notice. -->
                <div class="border-t border-line p-5 lg:border-t-0">
                    <p class="label-caps">Drift</p>
                    <p
                        class="numeric mt-1.5 flex items-center gap-1.5 text-2xl font-semibold"
                        :class="data.registry.drifted_checkouts > 0 ? 'text-warning-text' : ''"
                    >
                        <AppIcon
                            v-if="data.registry.drifted_checkouts > 0"
                            name="warning"
                            class="size-5 shrink-0"
                        />
                        {{ data.registry.drifted_checkouts }}
                    </p>
                    <p class="mt-0.5 text-xs text-pretty text-fg-subtle">
                        checkouts whose disk ref differs from their pin
                    </p>
                </div>
            </section>

            <!-- A farm with no config repository registered is a farm this app
                 cannot deploy wiki config for, which is worth saying once rather
                 than leaving to be discovered in the wizard. -->
            <div
                v-if="!data.registry.has_config_repository && can('manage_repositories')"
                class="flex items-start gap-2.5 rounded-xl border border-info-line bg-info-surface px-4 py-3.5"
            >
                <AppIcon name="info" class="mt-0.5 size-4 shrink-0 text-info-text" />
                <div class="min-w-0 text-sm text-info-text">
                    <p class="font-medium">No config repository is registered.</p>
                    <p class="mt-1 text-pretty">
                        Wiki config lives outside the version trees, at
                        <code class="rounded-sm bg-info-line/30 px-1 py-0.5 font-mono text-xs">{{
                            session.settings.config_dir
                        }}</code>. It takes the git URL and nothing else.
                    </p>
                    <RouterLink to="/deployments/repositories/config" class="mt-2.5 inline-flex font-medium underline">
                        Add the config repository
                    </RouterLink>
                </div>
            </div>

            <CardPanel
                :title="`Running now (${data.active.length})`"
                subtitle="Live from the queue worker. Steps update over the websocket, with a poll behind it."
            >
                <p v-if="data.active.length === 0" class="text-sm text-fg-subtle">
                    Nothing is deploying. The fleet is quiet.
                </p>

                <div v-else class="space-y-6">
                    <article v-for="deployment in data.active" :key="deployment.id">
                        <header class="flex flex-wrap items-center gap-x-2 gap-y-1.5">
                            <RouterLink :to="`/deployments/${deployment.id}`" class="numeric link font-medium">
                                #{{ deployment.id }}
                            </RouterLink>
                            <StatusBadge :label="deployment.status_label" :tone="deployment.status_tone" />
                            <StatusBadge :label="deployment.intent_label" :tone="deployment.intent_tone" />
                            <span class="text-sm text-fg-muted">{{ deployment.summary }}</span>
                            <span class="numeric ms-auto text-xs text-fg-subtle">
                                {{ deployment.creator }} · {{ duration(deployment.duration) }}
                            </span>
                        </header>

                        <div
                            v-if="deployment.awaiting_decision"
                            class="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-lg border border-warning-line bg-warning-surface px-4 py-3 text-sm text-warning-text"
                        >
                            <AppIcon name="warning" class="size-4 shrink-0" />
                            <p class="font-medium">{{ deployment.pending_decision_label }} — waiting for a decision.</p>
                            <RouterLink
                                :to="`/deployments/${deployment.id}`"
                                class="ms-auto font-medium whitespace-nowrap underline"
                            >
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
                    <AppButton to="/deployments/history" variant="ghost" trailing-icon="chevron-right">
                        Full history
                    </AppButton>
                </template>

                <!-- Scrolls inside the panel rather than widening the page: a
                     summary line can be long, and the page must not go sideways. -->
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="label-caps border-b border-line text-start">
                            <tr>
                                <th class="px-5 py-2.5 font-semibold">#</th>
                                <th class="px-5 py-2.5 font-semibold">Status</th>
                                <th class="px-5 py-2.5 font-semibold">What</th>
                                <th class="px-5 py-2.5 font-semibold">By</th>
                                <th class="px-5 py-2.5 font-semibold">When</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr
                                v-for="deployment in data.recent"
                                :key="deployment.id"
                                class="hover:bg-sunken motion-safe:transition-colors motion-safe:duration-100"
                            >
                                <td class="numeric px-5 py-2.5">
                                    <RouterLink :to="`/deployments/${deployment.id}`" class="link">
                                        {{ deployment.id }}
                                    </RouterLink>
                                </td>
                                <td class="px-5 py-2.5">
                                    <StatusBadge :label="deployment.status_label" :tone="deployment.status_tone" />
                                </td>
                                <td class="px-5 py-2.5">{{ deployment.summary }}</td>
                                <td class="px-5 py-2.5 text-fg-muted">{{ deployment.creator }}</td>
                                <td class="numeric px-5 py-2.5 whitespace-nowrap text-fg-subtle">
                                    {{ relative(deployment.created_at) }}
                                </td>
                            </tr>
                            <tr v-if="data.recent.length === 0">
                                <td colspan="5" class="px-5 py-8 text-center text-sm text-fg-subtle">
                                    Nothing has been deployed from this console yet.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardPanel>
        </div>
    </LoadState>
</template>
