<script setup>
import { onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import { ApiError, api, endpoint } from '../../../api';
import CardPanel from '../../../components/CardPanel.vue';
import LoadState from '../../../components/LoadState.vue';
import PaginationBar from '../../../components/PaginationBar.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
import { duration, relative } from '../../../format';

/**
 * Deploy history — the thing the old CLI's single-run JSON state file could never
 * give you.
 */
const route = useRoute();
const router = useRouter();

const data = ref(null);
const loading = ref(true);
const error = ref(null);
const status = ref(route.query.status ?? '');
const page = ref(Number(route.query.page ?? 1));

const load = async () => {
    loading.value = true;

    try {
        data.value = await api.get(endpoint('deployments'), {
            params: { status: status.value, page: page.value },
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

watch([status, page], () => {
    router.replace({ query: { status: status.value || undefined, page: page.value > 1 ? page.value : undefined } });
    load();
});

onMounted(load);
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">History</h1>
                <p class="mt-1.5 max-w-prose text-sm text-pretty text-fg-muted">
                    Every deployment this console has run, including the ones that failed — what changed, who ran
                    it, and what each host did.
                </p>
            </div>

            <!-- Visible label rather than a bare select: "All statuses" reads as
                 the current value, not as what the control filters on. -->
            <label class="flex flex-none items-center gap-2 text-sm text-fg-muted">
                Status
                <select v-model="status" class="input-control" @change="page = 1">
                    <option value="">All</option>
                    <option v-for="option in data?.statuses ?? []" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </label>
        </div>

        <LoadState
            :loading="loading"
            :error="error"
            :empty="(data?.data?.length ?? 0) === 0"
            :empty-title="status ? 'No deployments with that status' : 'No deployments yet'"
            :empty-message="
                status
                    ? 'Every deployment this console has run is kept, including the ones that failed. Try another status.'
                    : 'Every deployment this console runs is recorded here — what changed, who ran it, and what each host did.'
            "
            :skeleton-rows="6"
            @retry="load"
        >
            <CardPanel flush>
                <table class="w-full text-sm">
                    <thead class="label-caps border-b border-line text-start">
                        <tr>
                            <th class="px-5 py-2">#</th>
                            <th class="px-5 py-2">Status</th>
                            <th class="px-5 py-2">Intent</th>
                            <th class="px-5 py-2">What</th>
                            <th class="px-5 py-2">By</th>
                            <th class="px-5 py-2">Took</th>
                            <th class="px-5 py-2">When</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="deployment in data.data" :key="deployment.id" class="align-top">
                            <td class="px-5 py-2">
                                <RouterLink :to="`/deployments/${deployment.id}`" class="link">
                                    {{ deployment.id }}
                                </RouterLink>
                            </td>
                            <td class="px-5 py-2">
                                <StatusBadge :label="deployment.status_label" :tone="deployment.status_tone" />
                            </td>
                            <td class="px-5 py-2">
                                <StatusBadge :label="deployment.intent_label" :tone="deployment.intent_tone" />
                            </td>
                            <td class="px-5 py-2">
                                {{ deployment.summary }}
                                <span v-if="deployment.is_rollback" class="block text-xs text-warning-text">
                                    rolls back #{{ deployment.rolls_back_id }}
                                </span>
                                <span
                                    v-if="(deployment.rollback_ids?.length ?? 0) > 0"
                                    class="block text-xs text-fg-subtle"
                                >
                                    rolled back by #{{ deployment.rollback_ids.join(', #') }}
                                </span>
                            </td>
                            <td class="px-5 py-2 text-fg-muted">{{ deployment.creator }}</td>
                            <td class="px-5 py-2 text-fg-subtle">{{ duration(deployment.duration) }}</td>
                            <td class="px-5 py-2 text-fg-subtle">{{ relative(deployment.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </CardPanel>

            <PaginationBar :meta="data.meta" unit="deployment" @go="page = $event" />
        </LoadState>
    </div>
</template>
