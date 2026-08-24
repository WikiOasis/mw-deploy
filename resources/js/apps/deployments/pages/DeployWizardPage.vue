<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { pluralise } from '../../../format';

import { ApiError, api, endpoint } from '../../../api';
import AppButton from '../../../components/AppButton.vue';
import AppIcon from '../../../components/AppIcon.vue';
import CardPanel from '../../../components/CardPanel.vue';
import CheckoutPicker from '../components/CheckoutPicker.vue';
import DeployOptions from '../components/DeployOptions.vue';
import LoadState from '../../../components/LoadState.vue';
import PlanReview from '../components/PlanReview.vue';
import { flash, flashError, session } from '../../../store';

/**
 * The deploy, undeploy and staging-sync wizards. One component, three intents —
 * but deliberately three routes: removing checkouts off the whole fleet, or
 * shipping whatever staging currently holds, should not be one mis-click away
 * from updating a ref, so there is no mode toggle here.
 *
 * Three steps: pick, set options, review. A staging sync has nothing to pick, so
 * it drops that step rather than showing an empty picker. The review step is not
 * decoration — it shows the exact `salt` calls that will run, built by the
 * server's planner.
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
const isSyncStaging = computed(() => props.intent === 'sync_staging');

/**
 * The three steps and where you are in them.
 *
 * Each label names what that step is *for*, and each button below names the step
 * it goes to — so the flow reads "Choose where it goes", "Review the plan", then
 * the actual consequence. A generic "Continue" on one step and a specific "Review
 * the plan" on the next makes them look like different kinds of control.
 */
const STEP_ORDER = computed(() => (isSyncStaging.value ? ['options', 'review'] : ['pick', 'options', 'review']));

const STEP_LABELS = { pick: 'Select', options: 'Where it goes', review: 'Review' };

/** Where a staging sync starts, since there is nothing to select first. */
const firstStep = computed(() => STEP_ORDER.value[0]);

/** What the final button says, which is the consequence and not "Confirm". */
const confirmLabel = computed(() => {
    if (plan.value?.removes_anything) {
        return 'Remove from the fleet';
    }

    if (settings.value?.stagingOnly) {
        return isSyncStaging.value ? 'Sync the production tree on staging' : 'Deploy to staging';
    }

    const where = settings.value?.allServers
        ? 'all appservers'
        : pluralise(settings.value?.servers.length ?? 0, 'appserver');

    return isSyncStaging.value ? `Sync staging to ${where}` : `Deploy to ${where}`;
});

const steps = computed(() => {
    const at = STEP_ORDER.value.indexOf(step.value);

    return STEP_ORDER.value.map((key, index) => ({
        key,
        label: STEP_LABELS[key],
        state: index < at ? 'done' : index === at ? 'current' : 'upcoming',
    }));
});

const selectedIds = computed(() => Object.keys(selection.value).map(Number));

const load = async () => {
    loading.value = true;
    step.value = firstStep.value;

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
        load();
    },
);

/**
 * Patches registered against a selected checkout, pre-ticked. The registry exists
 * so nobody retypes a patch target; pre-selecting them is the other half of that.
 */
const relevantPatches = computed(() => {
    if (isUndeploy.value || isSyncStaging.value) {
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
    // A staging sync selects nothing: the tree as it stands is what ships, and the
    // server refuses items under this intent rather than quietly ignoring them.
    items: isSyncStaging.value
        ? []
        : Object.entries(selection.value).map(([id, choice]) => ({
              repository_version_id: Number(id),
              ...(isUndeploy.value
                  ? {}
                  : { ref_type: choice.refType, ref_value: (choice.refValue ?? '').trim() }),
          })),
    patches: isUndeploy.value || isSyncStaging.value ? [] : patchIds.value,
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
            step.value = firstStep.value;
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
                <h1 class="text-xl font-semibold">
                    {{
                        isUndeploy
                            ? 'Undeploy from the fleet'
                            : isSyncStaging
                              ? 'Sync staging as it stands'
                              : 'New deployment'
                    }}
                </h1>
                <p class="mt-1.5 max-w-prose text-sm text-pretty text-fg-muted">
                    <template v-if="isUndeploy">
                        Removes checkouts from the staging tree and from every server, one
                        <code class="font-mono">rm -rf</code> per host. Reversible: rolling the removal back
                        clones them again at the ref they were on.
                    </template>
                    <template v-else-if="isSyncStaging">
                        Ships <code class="font-mono">{{ options.tree?.staging }}</code> exactly as it is right
                        now — no fetch, no checkout, nothing selected. For fixes made directly on staging: a
                        hand-edited file, a patch applied by hand, a checkout someone repaired in place. Because
                        no ref is recorded, there is no undo point, and rollback is not offered afterwards.
                    </template>
                    <template v-else>
                        Pick checkouts and refs, choose where it goes, then review every Salt call before it runs.
                    </template>
                </p>
            </header>

            <!-- Where you are in the flow. The current step is marked with
                 `aria-current`, and a finished one carries a tick as well as a
                 colour, so the three states are not told apart by hue alone. -->
            <nav aria-label="Deployment steps">
                <ol class="flex flex-wrap items-center gap-x-2 gap-y-2 text-sm">
                    <li v-for="(entry, index) in steps" :key="entry.key" class="flex items-center gap-2">
                        <AppIcon v-if="index > 0" name="chevron-right" class="size-3.5 text-fg-faint" />
                        <span
                            class="flex items-center gap-2"
                            :aria-current="step === entry.key ? 'step' : undefined"
                        >
                            <span
                                class="numeric inline-flex size-5 flex-none items-center justify-center rounded-full text-2xs font-semibold"
                                :class="
                                    entry.state === 'done'
                                        ? 'bg-success-surface text-success-text'
                                        : entry.state === 'current'
                                          ? 'bg-accent text-accent-fg'
                                          : 'bg-sunken text-fg-subtle'
                                "
                                aria-hidden="true"
                            >
                                <AppIcon v-if="entry.state === 'done'" name="check" class="size-3" />
                                <template v-else>{{ index + 1 }}</template>
                            </span>
                            <span :class="entry.state === 'upcoming' ? 'text-fg-subtle' : 'font-medium text-fg'">
                                {{ entry.label }}
                            </span>
                        </span>
                    </li>
                </ol>
            </nav>

            <div
                v-if="validation.length > 0"
                class="rounded-md border border-danger-line bg-danger-surface px-4 py-3 text-sm text-danger-text"
            >
                <p class="font-medium">This selection was refused:</p>
                <ul class="mt-1 list-disc space-y-0.5 ps-5">
                    <li v-for="message in validation" :key="message">{{ message }}</li>
                </ul>
            </div>

            <template v-if="step === 'pick' && !isSyncStaging">
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
                                    class="mt-1 size-4 rounded border-line-strong"
                                    :value="patch.id"
                                />
                                <span>
                                    <span class="font-medium">{{ patch.name }}</span>
                                    <span class="block text-xs text-fg-subtle">
                                        {{ patch.target_label }} ·
                                        <code class="font-mono">{{ patch.target_path }}</code>
                                    </span>
                                    <span
                                        v-if="patch.last_check_ok === false"
                                        class="block text-xs text-danger-text"
                                    >
                                        Last dry run failed: {{ patch.last_check_detail }}
                                    </span>
                                </span>
                            </label>
                        </li>
                    </ul>
                </CardPanel>

                <div class="flex flex-wrap items-center justify-end gap-3">
                    <p v-if="selectedIds.length > 0" class="numeric me-auto text-sm text-fg-subtle">
                        {{ pluralise(selectedIds.length, 'checkout') }} selected
                    </p>
                    <AppButton
                        variant="primary"
                        trailing-icon="chevron-right"
                        :disabled="selectedIds.length === 0"
                        @click="step = 'options'"
                    >
                        Choose where it goes
                    </AppButton>
                </div>
            </template>

            <template v-else-if="step === 'options'">
                <CardPanel
                    v-if="isSyncStaging"
                    title="What will be synced"
                    subtitle="The whole tree, as it stands on disk."
                >
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-medium text-fg-subtle">From</dt>
                            <dd class="mt-0.5 font-mono text-xs">{{ options.tree?.staging }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-fg-subtle">To</dt>
                            <dd class="mt-0.5 font-mono text-xs">{{ options.tree?.production }}</dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs text-fg-subtle">
                        Whatever is on staging goes out, including anything staged by someone else since it was
                        last deployed. Check the tree before continuing.
                    </p>
                </CardPanel>

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

                <!-- A staging sync has no selection step, so there is nothing to
                     go back to and the one button sits on its own. -->
                <div
                    class="flex flex-wrap items-center gap-3"
                    :class="isSyncStaging ? 'justify-end' : 'justify-between'"
                >
                    <AppButton v-if="!isSyncStaging" variant="ghost" icon="arrow-left" @click="step = 'pick'">
                        Back to selection
                    </AppButton>
                    <AppButton
                        variant="primary"
                        trailing-icon="chevron-right"
                        :loading="busy"
                        @click="review"
                    >
                        Review the plan
                    </AppButton>
                </div>
            </template>

            <template v-else>
                <CardPanel
                    :title="`Review — ${pluralise(plan.call_count, 'Salt call')}`"
                    subtitle="This is the exact sequence that will run, in order."
                >
                    <PlanReview :plan="plan" />
                </CardPanel>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <AppButton variant="ghost" icon="arrow-left" @click="step = 'options'">Back to options</AppButton>
                    <!-- The last button names the consequence rather than saying
                         "Confirm": this is the click that reaches production, and it
                         has to be answerable without scrolling back up. -->
                    <AppButton
                        :variant="plan.removes_anything ? 'danger' : 'primary'"
                        :loading="busy"
                        @click="confirm"
                    >
                        {{ confirmLabel }}
                    </AppButton>
                </div>

                <p class="text-xs text-fg-subtle">
                    Queued as a single job on the worker. You will land on its live view;
                    {{ session.settings.staging_host }} runs the preparation steps.
                </p>
            </template>
        </div>
    </LoadState>
</template>
