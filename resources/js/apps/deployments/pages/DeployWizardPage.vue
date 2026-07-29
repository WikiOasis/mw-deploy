<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

import { ApiError, api, endpoint } from '../../../api';
import CardPanel from '../../../components/CardPanel.vue';
import CheckoutPicker from '../components/CheckoutPicker.vue';
import DeployOptions from '../components/DeployOptions.vue';
import LoadState from '../../../components/LoadState.vue';
import PlanReview from '../components/PlanReview.vue';
import { flash, flashError, session } from '../../../store';

/**
 * The deploy and undeploy wizards. One component, two intents — but deliberately
 * two routes: removing checkouts off the whole fleet should not be one mis-click
 * away from updating them, so there is no mode toggle here.
 *
 * Three steps: pick, set options, review. The review step is not decoration — it
 * shows the exact `salt` calls that will run, built by the server's planner.
 */
const props = defineProps({
    intent: { type: String, default: 'deploy' },
});

const router = useRouter();

const options = ref(null);
const loading = ref(true);
const error = ref(null);

const step = ref('pick');
const busy = ref(false);
const plan = ref(null);
const validation = ref([]);

const selection = ref({});
const patchIds = ref([]);
const settings = ref({
    stagingOnly: true,
    allServers: true,
    servers: [],
    rollout: false,
    l10n: false,
    force: false,
    parallel: 1,
});

const isUndeploy = computed(() => props.intent === 'undeploy');
const selectedIds = computed(() => Object.keys(selection.value).map(Number));

const load = async () => {
    loading.value = true;

    try {
        options.value = await api.get(endpoint('deployments/wizard'), {
            params: { intent: props.intent },
        });

        settings.value = {
            ...settings.value,
            stagingOnly: options.value.defaults.staging_only,
            parallel: options.value.defaults.parallel,
        };

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

// Switching between /deployments/new and /deployments/undeploy reuses this
// component, so the selection has to be dropped rather than carried across intents.
watch(
    () => props.intent,
    () => {
        selection.value = {};
        patchIds.value = [];
        plan.value = null;
        step.value = 'pick';
        load();
    },
);

/**
 * Patches registered against a selected checkout, pre-ticked. The registry exists
 * so nobody retypes a patch target; pre-selecting them is the other half of that.
 */
const relevantPatches = computed(() => {
    if (isUndeploy.value) {
        return [];
    }

    const ids = new Set(selectedIds.value);

    return (options.value?.patches ?? []).filter((patch) => ids.has(patch.target_checkout_id));
});

watch(relevantPatches, (patches) => {
    patchIds.value = patches.map((patch) => patch.id);
});

const payload = () => ({
    intent: props.intent,
    items: Object.entries(selection.value).map(([id, choice]) => ({
        repository_version_id: Number(id),
        ...(isUndeploy.value
            ? {}
            : { ref_type: choice.refType, ref_value: (choice.refValue ?? '').trim() }),
    })),
    patches: isUndeploy.value ? [] : patchIds.value,
    servers: settings.value.stagingOnly || settings.value.allServers ? [] : settings.value.servers,
    parallel: settings.value.stagingOnly ? 1 : settings.value.parallel,
    force: settings.value.force,
    l10n: isUndeploy.value ? false : settings.value.l10n,
    rollout: settings.value.stagingOnly ? false : settings.value.rollout,
    staging_only: settings.value.stagingOnly,
});

const review = async () => {
    busy.value = true;
    validation.value = [];

    try {
        plan.value = await api.post(endpoint('deployments/plan'), payload());
        step.value = 'review';
    } catch (thrown) {
        if (thrown instanceof ApiError && thrown.isValidation) {
            validation.value = thrown.all();
        } else {
            flashError(thrown);
        }
    } finally {
        busy.value = false;
    }
};

const confirm = async () => {
    busy.value = true;

    try {
        // Post the payload the server echoed back with the plan, so what runs is
        // what was reviewed rather than whatever the form holds now.
        const created = await api.post(endpoint('deployments'), plan.value.payload);

        flash(created.message);
        router.push(`/deployments/${created.id}`);
    } catch (thrown) {
        if (thrown instanceof ApiError && thrown.isValidation) {
            validation.value = thrown.all();
            step.value = 'pick';
        } else {
            flashError(thrown);
        }
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <LoadState :loading="loading" :error="error" @retry="load">
        <div v-if="options" class="space-y-6">
            <header>
                <h1 class="text-lg font-semibold tracking-tight">
                    {{ isUndeploy ? 'Undeploy from the fleet' : 'New deployment' }}
                </h1>
                <p class="mt-1 text-sm text-slate-500">
                    <template v-if="isUndeploy">
                        Removes checkouts from the staging tree and from every server, one
                        <code class="font-mono">rm -rf</code> per host. Reversible: rolling the removal back
                        clones them again at the ref they were on.
                    </template>
                    <template v-else>
                        Pick checkouts and refs, choose where it goes, then review every Salt call before it runs.
                    </template>
                </p>
            </header>

            <ol class="flex flex-wrap gap-4 text-sm">
                <li
                    v-for="(label, key) in { pick: '1. Select', options: '2. Options', review: '3. Review' }"
                    :key="key"
                    class="font-medium"
                    :class="step === key ? 'text-slate-900 underline' : 'text-slate-400'"
                >
                    {{ label }}
                </li>
            </ol>

            <div
                v-if="validation.length > 0"
                class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
            >
                <p class="font-medium">This selection was refused:</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    <li v-for="message in validation" :key="message">{{ message }}</li>
                </ul>
            </div>

            <template v-if="step === 'pick'">
                <CardPanel
                    title="What to deploy"
                    :subtitle="
                        isUndeploy
                            ? 'Only checkouts that are actually on disk can be removed.'
                            : 'One row per checkout. Each carries its own ref, so 1.45 can go to REL1_45 while 1.46 goes to REL1_46.'
                    "
                >
                    <CheckoutPicker
                        v-model="selection"
                        :repositories="options.repositories"
                        :types="options.types"
                        :intent="props.intent"
                    />
                </CardPanel>

                <CardPanel v-if="relevantPatches.length > 0" title="Patches" subtitle="Pre-selected from the registry">
                    <ul class="space-y-2 text-sm">
                        <li v-for="patch in relevantPatches" :key="patch.id">
                            <label class="flex items-start gap-2">
                                <input
                                    v-model="patchIds"
                                    type="checkbox"
                                    class="mt-1 rounded border-slate-300"
                                    :value="patch.id"
                                />
                                <span>
                                    <span class="font-medium">{{ patch.name }}</span>
                                    <span class="block text-xs text-slate-500">
                                        {{ patch.target_label }} ·
                                        <code class="font-mono">{{ patch.target_path }}</code>
                                    </span>
                                    <span
                                        v-if="patch.last_check_ok === false"
                                        class="block text-xs text-rose-700"
                                    >
                                        Last dry run failed: {{ patch.last_check_detail }}
                                    </span>
                                </span>
                            </label>
                        </li>
                    </ul>
                </CardPanel>

                <div class="flex justify-end">
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-40"
                        :disabled="selectedIds.length === 0"
                        @click="step = 'options'"
                    >
                        Continue — {{ selectedIds.length }} selected
                    </button>
                </div>
            </template>

            <template v-else-if="step === 'options'">
                <CardPanel title="Where it goes">
                    <DeployOptions
                        v-model="settings"
                        :appservers="options.appservers"
                        :proxies="options.proxies"
                        :intent="props.intent"
                        :can="options.can"
                        :max-parallel="options.defaults.max_parallel"
                    />
                </CardPanel>

                <div class="flex justify-between">
                    <button type="button" class="text-sm text-slate-600 underline" @click="step = 'pick'">
                        Back
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50"
                        :disabled="busy"
                        @click="review"
                    >
                        {{ busy ? 'Planning…' : 'Review the plan' }}
                    </button>
                </div>
            </template>

            <template v-else>
                <CardPanel
                    :title="`Review — ${plan.call_count} Salt call(s)`"
                    subtitle="This is the exact sequence that will run, in order."
                >
                    <PlanReview :plan="plan" />
                </CardPanel>

                <div class="flex justify-between">
                    <button type="button" class="text-sm text-slate-600 underline" @click="step = 'options'">
                        Back
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                        :class="plan.removes_anything ? 'bg-rose-600 hover:bg-rose-500' : 'bg-slate-900 hover:bg-slate-700'"
                        :disabled="busy"
                        @click="confirm"
                    >
                        {{
                            busy
                                ? 'Queueing…'
                                : plan.removes_anything
                                  ? 'Remove from the fleet'
                                  : settings.stagingOnly
                                    ? 'Deploy to staging'
                                    : `Deploy to ${settings.allServers ? 'all appservers' : `${settings.servers.length} appserver(s)`}`
                        }}
                    </button>
                </div>

                <p class="text-xs text-slate-500">
                    Queued as a single job on the worker. You will land on its live view;
                    {{ session.settings.staging_host }} runs the preparation steps.
                </p>
            </template>
        </div>
    </LoadState>
</template>
