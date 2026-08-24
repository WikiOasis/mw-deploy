<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';

import { ApiError, api, endpoint } from '../../../api';
import AppButton from '../../../components/AppButton.vue';
import CardPanel from '../../../components/CardPanel.vue';
import LoadState from '../../../components/LoadState.vue';
import ModalDialog from '../../../components/ModalDialog.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
import StepList from '../components/StepList.vue';
import { dateTime, duration, pluralise } from '../../../format';
import { useDeploymentState } from '../../../live';
import { flash, flashError } from '../../../store';

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
const showForceFail = ref(false);

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
    tone: state.value?.status_tone ?? deployment.value?.status_tone,
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

const forceFail = async () => {
    busy.value = true;

    try {
        const payload = await api.post(endpoint(`deployments/${props.id}/force-fail`), {});

        flash(payload.message);
        showForceFail.value = false;
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
            <header>
                <AppButton to="/deployments/history" variant="ghost" icon="arrow-left" class="-ms-3 mb-2">
                    History
                </AppButton>

                <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <h1 class="numeric text-xl font-semibold">Deployment #{{ deployment.id }}</h1>
                        <StatusBadge :label="status.label" :tone="status.tone" />
                        <StatusBadge :label="deployment.intent_label" :tone="deployment.intent_tone" />
                        <!-- Whether this page is being pushed to or polled. A dot on
                             its own would be colour-only, so the word stays with it. -->
                        <span
                            v-if="!finished"
                            class="inline-flex items-center gap-1.5 text-xs"
                            :class="live ? 'text-success-text' : 'text-fg-subtle'"
                        >
                            <span
                                class="size-1.5 rounded-full"
                                :class="live ? 'bg-success-solid motion-safe:animate-pulse' : 'bg-fg-faint'"
                                aria-hidden="true"
                            />
                            {{ live ? 'Live' : 'Polling' }}
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <RouterLink
                            v-if="deployment.rolls_back_id"
                            :to="`/deployments/${deployment.rolls_back_id}`"
                            class="link-quiet"
                        >
                            Rolls back #{{ deployment.rolls_back_id }}
                        </RouterLink>
                        <button
                            v-if="deployment.can.cancel"
                            type="button"
                            class="btn btn-secondary disabled:opacity-50"
                            :disabled="busy"
                            @click="showCancel = true"
                        >
                            Cancel
                        </button>
                        <button
                            v-if="deployment.can.abort && !awaitingDecision"
                            type="button"
                            class="btn btn-danger"
                            :disabled="busy"
                            @click="showAbort = true"
                        >
                            Abort
                        </button>
                        <button
                            v-if="deployment.can.rollback"
                            type="button"
                            class="btn btn-secondary"
                            :disabled="busy"
                            @click="showRollback = true"
                        >
                            Roll back
                        </button>
                        <button
                            v-if="deployment.can.force_fail"
                            type="button"
                            class="btn btn-danger-quiet"
                            :disabled="busy"
                            @click="showForceFail = true"
                        >
                            Force fail
                        </button>
                    </div>
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
                            <dt class="text-fg-subtle">{{ key }}</dt>
                            <dd class="font-mono text-xs">{{ value }}</dd>
                        </div>
                    </dl>

                    <p v-if="!deployment.can.decide" class="text-sm text-fg-subtle">
                        You do not have <code class="font-mono">deploy.decide</code>, so you cannot answer this.
                        It will apply <code class="font-mono">{{ deployment.options.force ? 'continue' : 'the configured default' }}</code>
                        if nobody does.
                    </p>

                    <div v-else class="grid gap-2 sm:grid-cols-3">
                        <button
                            v-for="option in deployment.decisions"
                            :key="option.value"
                            type="button"
                            class="rounded-md border px-3 py-2 text-start text-sm hover:bg-sunken disabled:opacity-50"
                            :class="
                                option.value === 'continue'
                                    ? 'border-warning-line'
                                    : option.value === 'abort_and_rollback'
                                      ? 'border-danger-line'
                                      : 'border-line-strong'
                            "
                            :disabled="busy"
                            @click="decide(option.value)"
                        >
                            <span class="font-medium">{{ option.label }}</span>
                            <span class="mt-1 block text-xs text-fg-subtle">{{ option.description }}</span>
                        </button>
                    </div>
                </div>
            </CardPanel>

            <div
                v-if="failureReason"
                class="rounded-md border border-danger-line bg-danger-surface px-4 py-3 text-sm text-danger-text"
            >
                <p class="font-medium">This deployment failed.</p>
                <p class="mt-1 font-mono text-xs">{{ failureReason }}</p>
            </div>

            <div
                v-if="record.newer_touching_same_repos.length > 0 && deployment.can.rollback"
                class="rounded-md border border-warning-line bg-warning-surface px-4 py-3 text-sm text-warning-text"
            >
                <p class="font-medium">
                    {{ pluralise(record.newer_touching_same_repos.length, 'later deployment') }} have touched the same
                    checkouts.
                </p>
                <p class="mt-1 text-xs">
                    Rolling this one back would revert their work too, because it restores the refs recorded
                    <em>before</em> this deployment ran:
                    <RouterLink
                        v-for="newer in record.newer_touching_same_repos"
                        :key="newer.id"
                        :to="`/deployments/${newer.id}`"
                        class="link me-2"
                    >
                        #{{ newer.id }}
                    </RouterLink>
                </p>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <CardPanel title="Summary" class="lg:col-span-2">
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="label-caps">Queued by</dt>
                            <dd>{{ deployment.creator }} · {{ dateTime(deployment.created_at) }}</dd>
                        </div>
                        <div>
                            <dt class="label-caps">Duration</dt>
                            <dd>{{ duration(state?.duration ?? deployment.duration) }}</dd>
                        </div>
                        <div>
                            <dt class="label-caps">Options</dt>
                            <dd class="flex flex-wrap gap-1">
                                <span
                                    v-for="flag in deployment.option_flags"
                                    :key="flag"
                                    class="rounded bg-sunken px-1.5 py-0.5 text-xs"
                                >
                                    {{ flag }}
                                </span>
                            </dd>
                        </div>
                        <div v-if="deployment.decision_response">
                            <dt class="label-caps">Decision</dt>
                            <dd>
                                {{ deployment.decision_response_label }}
                                <span class="text-fg-subtle">
                                    by {{ deployment.decided_by ?? 'timeout' }}
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <h3 class="label-caps mt-4">Line items</h3>
                    <ul class="mt-1 divide-y divide-line text-sm">
                        <li v-for="item in deployment.refs" :key="item.id" class="flex flex-wrap gap-2 py-1.5">
                            <span
                                class="rounded-sm border px-1.5 py-0.5 text-2xs font-medium"
                                :class="
                                    item.action === 'undeploy'
                                        ? 'border-warning-line bg-warning-surface text-warning-text'
                                        : 'border-line-strong bg-sunken text-fg-muted'
                                "
                            >
                                {{ item.action_label }}
                            </span>
                            <span>{{ item.name }}</span>
                            <span v-if="item.version" class="text-fg-subtle">({{ item.version }})</span>
                            <code class="ms-auto font-mono text-xs">{{ item.short_ref }}</code>
                        </li>
                        <!-- A staging sync has none by design, so say what shipped
                             instead of leaving the heading over an empty list. -->
                        <li v-if="!deployment.refs?.length" class="py-1.5 text-fg-subtle">
                            {{ deployment.summary }}
                        </li>
                    </ul>

                    <div v-if="deployment.patches?.length" class="mt-4">
                        <h3 class="label-caps">Patches</h3>
                        <ul class="mt-1 space-y-1 text-sm">
                            <li v-for="patch in deployment.patches" :key="patch.id">
                                {{ patch.name }}
                                <span :class="patch.applied ? 'text-success-text' : 'text-fg-subtle'">
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
                    <p v-if="(deployment.snapshots?.length ?? 0) === 0" class="text-sm text-fg-subtle">
                        No snapshots were recorded, so this deployment cannot be rolled back automatically.
                    </p>
                    <ul v-else class="space-y-2 text-sm">
                        <li v-for="(snapshot, index) in deployment.snapshots" :key="index">
                            <p class="font-medium">{{ snapshot.checkout }}</p>
                            <p class="font-mono text-xs text-fg-subtle">{{ snapshot.summary }}</p>
                            <p v-if="!snapshot.rollbackable" class="text-xs text-warning-text">
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
            <p class="mt-2 text-sm text-fg-muted">
                <code class="font-mono">--force</code> is deliberately dropped from a rollback's options — a rollback
                must not skip its own canary.
            </p>

            <template #footer>
                <button
                    type="button"
                    class="btn btn-secondary"
                    @click="showRollback = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="btn btn-danger"
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
                    class="btn btn-secondary"
                    @click="showCancel = false"
                >
                    Never mind
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
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
                    class="rounded-md border px-3 py-2 text-start text-sm hover:bg-sunken disabled:opacity-50"
                    :class="option.value === 'abort_and_rollback' ? 'border-danger-line' : 'border-line-strong'"
                    :disabled="busy"
                    @click="abort(option.value)"
                >
                    <span class="font-medium">{{ option.label }}</span>
                    <span class="mt-1 block text-xs text-fg-subtle">{{ option.description }}</span>
                </button>
            </div>

            <template #footer>
                <button
                    type="button"
                    class="btn btn-secondary"
                    @click="showAbort = false"
                >
                    Never mind
                </button>
            </template>
        </ModalDialog>

        <ModalDialog
            v-if="showForceFail"
            title="Force-fail this deployment?"
            subtitle="Only use this when the deployment is genuinely stuck, not merely slow."
            danger
            @close="showForceFail = false"
        >
            <p class="text-sm">
                Unlike Abort, this does not wait for a worker to notice — it unilaterally marks deployment
                #{{ deployment?.id }} failed and releases the fleet-wide deploy lock immediately. It exists for the
                one case nothing else covers: the worker that was running this deployment has died, so nothing is
                ever going to answer an abort request, and every deployment queued since has been silently stuck
                behind the lock this one never released.
            </p>
            <p class="mt-2 text-sm text-danger-text">
                If a worker is in fact still processing this deployment, forcing it here will not stop that work —
                it will just make this app's record of it wrong. Confirm the worker is actually gone first.
            </p>

            <template #footer>
                <button
                    type="button"
                    class="btn btn-secondary"
                    @click="showForceFail = false"
                >
                    Never mind
                </button>
                <button
                    type="button"
                    class="btn btn-danger"
                    :disabled="busy"
                    @click="forceFail"
                >
                    Force fail
                </button>
            </template>
        </ModalDialog>
    </LoadState>
</template>
