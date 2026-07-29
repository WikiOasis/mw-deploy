<script setup>
import { onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';

import { ApiError, api, endpoint } from '../../../api';
import CardPanel from '../../../components/CardPanel.vue';
import LoadState from '../../../components/LoadState.vue';
import StatusBadge from '../components/StatusBadge.vue';
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
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="text-lg font-semibold tracking-tight">History</h1>

            <select
                v-model="status"
                class="ml-auto rounded-md bg-white px-3 py-1.5 text-sm ring-1 ring-inset ring-slate-300"
                @change="page = 1"
            >
                <option value="">All statuses</option>
                <option v-for="option in data?.statuses ?? []" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
        </div>

        <LoadState
            :loading="loading"
            :error="error"
            :empty="(data?.data?.length ?? 0) === 0"
            empty-message="No deployments match this filter."
            @retry="load"
        >
            <CardPanel flush>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 text-left text-xs tracking-wide text-slate-500 uppercase">
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
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="deployment in data.data" :key="deployment.id" class="align-top">
                            <td class="px-5 py-2">
                                <RouterLink :to="`/deployments/${deployment.id}`" class="underline">
                                    {{ deployment.id }}
                                </RouterLink>
                            </td>
                            <td class="px-5 py-2">
                                <StatusBadge :label="deployment.status_label" :classes="deployment.status_classes" />
                            </td>
                            <td class="px-5 py-2">
                                <StatusBadge :label="deployment.intent_label" :classes="deployment.intent_classes" />
                            </td>
                            <td class="px-5 py-2">
                                {{ deployment.summary }}
                                <span v-if="deployment.is_rollback" class="block text-xs text-amber-700">
                                    rolls back #{{ deployment.rolls_back_id }}
                                </span>
                                <span
                                    v-if="(deployment.rollback_ids?.length ?? 0) > 0"
                                    class="block text-xs text-slate-500"
                                >
                                    rolled back by #{{ deployment.rollback_ids.join(', #') }}
                                </span>
                            </td>
                            <td class="px-5 py-2 text-slate-600">{{ deployment.creator }}</td>
                            <td class="px-5 py-2 text-slate-500">{{ duration(deployment.duration) }}</td>
                            <td class="px-5 py-2 text-slate-500">{{ relative(deployment.created_at) }}</td>
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
