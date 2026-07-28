<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';

import { ApiError, api, endpoint } from '../api';
import CardPanel from '../components/CardPanel.vue';
import LoadState from '../components/LoadState.vue';
import ModalDialog from '../components/ModalDialog.vue';
import StatusBadge from '../components/StatusBadge.vue';
import StepList from '../components/StepList.vue';
import { dateTime, duration } from '../format';
import { useDeploymentState } from '../live';
import { flash, flashError } from '../store';

/**
 * One deployment, live.
 *
 * Two data sources on purpose: the record (line items, undo point, patches) is
 * fetched once, and the volatile part (status, steps, the blocking prompt) comes
 * from the state endpoint over Echo with a poll behind it.
 */
const props = defineProps({
    id: { type: [String, Number], required: true },
});

const router = useRouter();

const record = ref(null);
const loading = ref(true);
const error = ref(null);
const busy = ref(false);
const showRollback = ref(false);
const showCancel = ref(false);
const showAbort = ref(false);

const { state, live, finished, start } = useDeploymentState(props.id);

const load = async () => {
    loading.value = true;

    try {
        const payload = await api.get(endpoint(`deployments/${props.id}`));

        record.value = payload;
        error.value = null;
    } catch (thrown) {
        if (thrown instanceof ApiError) {
            error.value = thrown;
        }
    } finally {
        loading.value = false;
    }
};

onMounted(async () => {
    await load();
    await start();
});

const deployment = computed(() => record.value?.data ?? null);

/** Live status wins over the fetched record, which is a snapshot from page load. */
const status = computed(() => ({
    value: state.value?.status ?? deployment.value?.status,
    label: state.value?.status_label ?? deployment.value?.status_label,
    classes: state.value?.status_classes ?? deployment.value?.status_classes,
}));

const awaitingDecision = computed(
    () => state.value?.awaiting_decision ?? deployment.value?.awaiting_decision ?? false,
);

const pendingLabel = computed(
    () => state.value?.pending_decision_label ?? deployment.value?.pending_decision_label,
);

const pendingContext = computed(
    () => state.value?.pending_decision_context ?? deployment.value?.pending_decision_context ?? {},
);

const failureReason = computed(() => state.value?.failure_reason ?? deployment.value?.failure_reason);

/** The abort modal reuses the same Abort / Abort-and-rollback copy the blocking
 *  canary prompt already renders, minus "Continue" — there is nothing to
 *  continue when nobody has hit a failure yet. */
const abortOptions = computed(() => (deployment.value?.decisions ?? []).filter((option) => option.value !== 'continue'));

/**
 * Steps come from the live feed when it has them, and from the initial record
 * otherwise — so a finished deployment renders instantly instead of waiting for a
 * state call that will say the same thing.
 */
const stepsByHost = computed(() => {
    if (state.value?.steps?.length) {
        return state.value.steps.reduce((grouped, step) => {
            (grouped[step.host] ??= []).push(step);

            return grouped;
        }, {});
    }

    return deployment.value?.steps_by_host ?? {};
});

const decide = async (decision) => {
    busy.value = true;

    try {
        const payload = await api.post(endpoint(`deployments/${props.id}/decision`), { decision });

        flash(payload.message);
    } catch (thrown) {
        flashError(thrown);
    } finally {
        busy.value = false;
    }
};

const rollback = async () => {
    busy.value = true;

    try {
        const payload = await api.post(endpoint(`deployments/${props.id}/rollback`), {});

        flash(payload.message);
        showRollback.value = false;
        router.push(`/deployments/${payload.id}`);
    } catch (thrown) {
        flashError(thrown);
    } finally {
        busy.value = false;
    }
};

const cancel = async () => {
    busy.value = true;

    try {
        const payload = await api.post(endpoint(`deployments/${props.id}/cancel`), {});

        flash(payload.message);
        showCancel.value = false;
    } catch (thrown) {
        flashError(thrown);
    } finally {
        busy.value = false;
    }
};

const abort = async (decision) => {
    busy.value = true;

    try {
        const payload = await api.post(endpoint(`deployments/${props.id}/abort`), { decision });

        flash(payload.message);
        showAbort.value = false;
    } catch (thrown) {
        flashError(thrown);
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <LoadState :loading="loading" :error="error" @retry="load">
        <div v-if="deployment" class="space-y-6">
            <header class="flex flex-wrap items-center gap-3">
                <h1 class="text-lg font-semibold tracking-tight">Deployment #{{ deployment.id }}</h1>
                <StatusBadge :label="status.label" :classes="status.classes" />
                <StatusBadge :label="deployment.intent_label" :classes="deployment.intent_classes" />
                <span v-if="!finished" class="text-xs" :class="live ? 'text-emerald-700' : 'text-slate-500'">
                    {{ live ? 'live' : 'polling' }}
                </span>

                <div class="ml-auto flex items-center gap-2 text-sm">
                    <RouterLink
                        v-if="deployment.rolls_back_id"
                        :to="`/deployments/${deployment.rolls_back_id}`"
                        class="text-slate-600 underline"
                    >
                        Rolls back #{{ deployment.rolls_back_id }}
                    </RouterLink>
                    <button
                        v-if="deployment.can.cancel"
                        type="button"
                        class="rounded-md px-3 py-1.5 font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 disabled:opacity-50"
                        :disabled="busy"
                        @click="showCancel = true"
                    >
                        Cancel
                    </button>
                    <button
                        v-if="deployment.can.abort && !awaitingDecision"
                        type="button"
                        class="rounded-md bg-rose-600 px-3 py-1.5 font-medium text-white hover:bg-rose-500 disabled:opacity-50"
                        :disabled="busy"
                        @click="showAbort = true"
                    >
                        Abort
                    </button>
                    <button
                        v-if="deployment.can.rollback"
                        type="button"
                        class="rounded-md bg-amber-600 px-3 py-1.5 font-medium text-white hover:bg-amber-500 disabled:opacity-50"
                        :disabled="busy"
                        @click="showRollback = true"
                    >
                        Roll back
                    </button>
                </div>
            </header>

            <!-- The blocking canary prompt. The queued job is sitting in a poll
                 loop waiting for this answer; an unanswered prompt eventually
                 applies the configured default rather than parking the fleet. -->
            <CardPanel
                v-if="awaitingDecision"
                :title="pendingLabel ?? 'A decision is needed'"
                subtitle="This deployment is parked. The other server pipelines keep moving until they need an answer too."
            >
                <div class="space-y-3">
                    <dl v-if="Object.keys(pendingContext).length > 0" class="grid gap-1 text-sm sm:grid-cols-2">
                        <div v-for="(value, key) in pendingContext" :key="key" class="flex gap-2">
                            <dt class="text-slate-500">{{ key }}</dt>
                            <dd class="font-mono text-xs">{{ value }}</dd>
                        </div>
                    </dl>

                    <p v-if="!deployment.can.decide" class="text-sm text-slate-500">
                        You do not have <code class="font-mono">deploy.decide</code>, so you cannot answer this.
                        It will apply <code class="font-mono">{{ deployment.options.force ? 'continue' : 'the configured default' }}</code>
                        if nobody does.
                    </p>

                    <div v-else class="grid gap-2 sm:grid-cols-3">
                        <button
                            v-for="option in deployment.decisions"
                            :key="option.value"
                            type="button"
                            class="rounded-md border px-3 py-2 text-left text-sm hover:bg-slate-50 disabled:opacity-50"
                            :class="
                                option.value === 'continue'
                                    ? 'border-amber-300'
                                    : option.value === 'abort_and_rollback'
                                      ? 'border-rose-300'
                                      : 'border-slate-300'
                            "
                            :disabled="busy"
                            @click="decide(option.value)"
                        >
                            <span class="font-medium">{{ option.label }}</span>
                            <span class="mt-1 block text-xs text-slate-500">{{ option.description }}</span>
                        </button>
                    </div>
                </div>
            </CardPanel>

            <div
                v-if="failureReason"
                class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"
            >
                <p class="font-medium">This deployment failed.</p>
                <p class="mt-1 font-mono text-xs">{{ failureReason }}</p>
            </div>

            <div
                v-if="record.newer_touching_same_repos.length > 0 && deployment.can.rollback"
                class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            >
                <p class="font-medium">
                    {{ record.newer_touching_same_repos.length }} later deployment(s) have touched the same
                    checkouts.
                </p>
                <p class="mt-1 text-xs">
                    Rolling this one back would revert their work too, because it restores the refs recorded
                    <em>before</em> this deployment ran:
                    <RouterLink
                        v-for="newer in record.newer_touching_same_repos"
                        :key="newer.id"
                        :to="`/deployments/${newer.id}`"
                        class="mr-2 underline"
                    >
                        #{{ newer.id }}
                    </RouterLink>
                </p>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <CardPanel title="Summary" class="lg:col-span-2">
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs tracking-wide text-slate-500 uppercase">Queued by</dt>
                            <dd>{{ deployment.creator }} · {{ dateTime(deployment.created_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs tracking-wide text-slate-500 uppercase">Duration</dt>
                            <dd>{{ duration(state?.duration ?? deployment.duration) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs tracking-wide text-slate-500 uppercase">Options</dt>
                            <dd class="flex flex-wrap gap-1">
                                <span
                                    v-for="flag in deployment.option_flags"
                                    :key="flag"
                                    class="rounded bg-slate-100 px-1.5 py-0.5 text-xs"
                                >
                                    {{ flag }}
                                </span>
                            </dd>
                        </div>
                        <div v-if="deployment.decision_response">
                            <dt class="text-xs tracking-wide text-slate-500 uppercase">Decision</dt>
                            <dd>
                                {{ deployment.decision_response_label }}
                                <span class="text-slate-500">
                                    by {{ deployment.decided_by ?? 'timeout' }}
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <h3 class="mt-4 text-xs font-semibold tracking-wide text-slate-500 uppercase">Line items</h3>
                    <ul class="mt-1 divide-y divide-slate-100 text-sm">
                        <li v-for="item in deployment.refs" :key="item.id" class="flex flex-wrap gap-2 py-1.5">
                            <span
                                class="rounded px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                :class="
                                    item.action === 'undeploy'
                                        ? 'bg-orange-100 text-orange-900 ring-orange-300'
                                        : 'bg-sky-100 text-sky-800 ring-sky-300'
                                "
                            >
                                {{ item.action_label }}
                            </span>
                            <span>{{ item.name }}</span>
                            <span v-if="item.version" class="text-slate-500">({{ item.version }})</span>
                            <code class="ml-auto font-mono text-xs">{{ item.short_ref }}</code>
                        </li>
                    </ul>

                    <div v-if="deployment.patches?.length" class="mt-4">
                        <h3 class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Patches</h3>
                        <ul class="mt-1 space-y-1 text-sm">
                            <li v-for="patch in deployment.patches" :key="patch.id">
                                {{ patch.name }}
                                <span :class="patch.applied ? 'text-emerald-700' : 'text-slate-500'">
                                    — {{ patch.applied ? 'applied' : 'not applied' }}
                                </span>
                            </li>
                        </ul>
                    </div>
                </CardPanel>

                <!-- The undo point, read before anything mutated staging. This is
                     what makes a rollback possible in both directions: it records
                     presence as well as ref. -->
                <CardPanel title="Undo point" subtitle="Recorded before staging was touched">
                    <p v-if="(deployment.snapshots?.length ?? 0) === 0" class="text-sm text-slate-500">
                        No snapshots were recorded, so this deployment cannot be rolled back automatically.
                    </p>
                    <ul v-else class="space-y-2 text-sm">
                        <li v-for="(snapshot, index) in deployment.snapshots" :key="index">
                            <p class="font-medium">{{ snapshot.checkout }}</p>
                            <p class="font-mono text-xs text-slate-500">{{ snapshot.summary }}</p>
                            <p v-if="!snapshot.rollbackable" class="text-xs text-amber-700">
                                not rollbackable — no previous ref was captured
                            </p>
                        </li>
                    </ul>
                </CardPanel>
            </div>

            <CardPanel title="Steps" subtitle="One row per Salt call, in the order they ran">
                <StepList :steps-by-host="stepsByHost" :staging-host="deployment.staging_host" />
            </CardPanel>
        </div>

        <ModalDialog
            v-if="showRollback"
            title="Roll back this deployment?"
            subtitle="A rollback is itself a deployment, with its own review trail."
            danger
            @close="showRollback = false"
        >
            <p class="text-sm">
                Every checkout this deployment touched goes back to the ref recorded in its undo point. A checkout
                it created is removed again; one it removed is cloned back.
            </p>
            <p class="mt-2 text-sm text-slate-600">
                <code class="font-mono">--force</code> is deliberately dropped from a rollback's options — a rollback
                must not skip its own canary.
            </p>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 text-sm ring-1 ring-slate-300"
                    @click="showRollback = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-md bg-rose-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-rose-500 disabled:opacity-50"
                    :disabled="busy"
                    @click="rollback"
                >
                    Queue rollback
                </button>
            </template>
        </ModalDialog>

        <ModalDialog
            v-if="showCancel"
            title="Cancel this deployment?"
            subtitle="It has not started, so nothing on staging or any appserver has been touched."
            @close="showCancel = false"
        >
            <p class="text-sm">
                Deployment #{{ deployment?.id }} will be marked aborted and the queued job will skip it when its
                turn comes.
            </p>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 text-sm ring-1 ring-slate-300"
                    @click="showCancel = false"
                >
                    Never mind
                </button>
                <button
                    type="button"
                    class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50"
                    :disabled="busy"
                    @click="cancel"
                >
                    Cancel deployment
                </button>
            </template>
        </ModalDialog>

        <ModalDialog
            v-if="showAbort"
            title="Abort this deployment?"
            subtitle="It stops at its next safe checkpoint — a Salt call already in flight still finishes."
            danger
            @close="showAbort = false"
        >
            <div class="grid gap-2 sm:grid-cols-2">
                <button
                    v-for="option in abortOptions"
                    :key="option.value"
                    type="button"
                    class="rounded-md border px-3 py-2 text-left text-sm hover:bg-slate-50 disabled:opacity-50"
                    :class="option.value === 'abort_and_rollback' ? 'border-rose-300' : 'border-slate-300'"
                    :disabled="busy"
                    @click="abort(option.value)"
                >
                    <span class="font-medium">{{ option.label }}</span>
                    <span class="mt-1 block text-xs text-slate-500">{{ option.description }}</span>
                </button>
            </div>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md px-3 py-1.5 text-sm ring-1 ring-slate-300"
                    @click="showAbort = false"
                >
                    Never mind
                </button>
            </template>
        </ModalDialog>
    </LoadState>
</template>
