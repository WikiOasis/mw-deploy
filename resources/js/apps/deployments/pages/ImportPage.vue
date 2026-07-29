<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';

import { ApiError, api, endpoint } from '../../../api';
import AppButton from '../../../components/AppButton.vue';
import AppIcon from '../../../components/AppIcon.vue';
import CardPanel from '../../../components/CardPanel.vue';
import LoadState from '../../../components/LoadState.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
import { pluralise, shortRef } from '../../../format';
import { usePolling } from '../../../live';
import { flash, flashError, refreshSession, session } from '../../../store';

/**
 * Adopting a MediaWiki farm the portal did not build.
 *
 * `mwdeploy-shim tree-scan` reads the deploy tree — every versions/<ver>, every
 * extension and skin inside it, their git remotes and current refs, and each
 * extension.json — and this screen turns that into registry rows. It is the
 * difference between installing the portal onto an existing farm and hand-typing a
 * hundred extensions into a form.
 *
 * Nothing here writes to disk. Applying an import creates or updates registry rows
 * only; the code it describes is already checked out.
 *
 * A scan runs on staging via `salt --async` and is polled with
 * `salt-run jobs.lookup_jid` rather than blocked on inline — a farm big enough to
 * take minutes to scan would otherwise have nginx or HAProxy give up on the
 * request before the portal did. Most scans still finish inside the very first
 * request (`scanning` never turns true); only a slow one falls back to polling.
 */
const plan = ref(null);
const loading = ref(true);
const error = ref(null);
const busy = ref(false);
const scanning = ref(false);
const scanId = ref(null);
const selected = ref(new Set());
const showInSync = ref(false);

/**
 * Manual fallback for when the Salt round-trip itself is what's broken (a
 * wedged minion, a farm too big for the scan's own timeout). The operator runs
 * `mwdeploy-shim tree-scan` by hand somewhere they can reach the tree and
 * pastes the JSON here — everything past this point (the plan, review, apply)
 * is the exact same code a normal scan goes through.
 */
const manualMode = ref(false);
const manualRoot = ref('');
const manualPayload = ref('');
const manualBusy = ref(false);
const manualError = ref(null);

/** @returns {boolean} true once the scan is done (success or failure) */
const applyScanResponse = (payload) => {
    if (payload.status === 'pending') {
        scanId.value = payload.scan_id;
        scanning.value = true;

        return false;
    }

    scanning.value = false;
    scanId.value = payload.scan_id ?? null;
    plan.value = payload.plan;
    selected.value = new Set(payload.plan.recommended_keys);
    error.value = null;

    return true;
};

const poller = usePolling(async () => {
    try {
        const payload = await api.get(endpoint('import'), { params: { scan: scanId.value } });

        return !applyScanResponse(payload);
    } catch (thrown) {
        scanning.value = false;
        // Whatever this was, don't leave the screen blank with no explanation —
        // a not-quite-ApiError is still worth telling the operator about.
        error.value = thrown instanceof ApiError
            ? thrown
            : new ApiError(0, { message: 'Something went wrong while checking on the scan.' });
        plan.value = null;

        return false;
    }
}, { interval: 4000 });

const load = async (fresh = false) => {
    loading.value = true;
    poller.stop();

    try {
        const payload = await api.get(endpoint('import'), { params: fresh ? { fresh: 1 } : {} });

        if (!applyScanResponse(payload)) {
            poller.start();
        }
    } catch (thrown) {
        scanning.value = false;
        error.value = thrown instanceof ApiError
            ? thrown
            : new ApiError(0, { message: 'Something went wrong while loading the import screen.' });
        plan.value = null;
    } finally {
        loading.value = false;
    }
};

onMounted(() => load());

const submitManual = async () => {
    manualBusy.value = true;
    manualError.value = null;

    try {
        const payload = await api.post(endpoint('import/manual'), {
            payload: manualPayload.value,
            root: manualRoot.value || undefined,
        });

        poller.stop();
        scanning.value = false;
        applyScanResponse(payload);
        error.value = null;
        manualMode.value = false;
    } catch (thrown) {
        manualError.value = thrown instanceof ApiError
            ? [thrown.message, thrown.body?.hint].filter(Boolean).join(' ')
            : 'Something went wrong parsing that.';
    } finally {
        manualBusy.value = false;
    }
};

const entries = computed(() => plan.value?.entries ?? []);
const actionable = computed(() => entries.value.filter((entry) => entry.actionable));
const inSync = computed(() => entries.value.filter((entry) => entry.action === 'in_sync'));
const blocked = computed(() => entries.value.filter((entry) => entry.action === 'unimportable'));

const isSelected = (key) => selected.value.has(key);

const toggle = (key) => {
    const next = new Set(selected.value);

    next.has(key) ? next.delete(key) : next.add(key);
    selected.value = next;
};

const selectAll = () => {
    selected.value = new Set(actionable.value.map((entry) => entry.key));
};

const selectRecommended = () => {
    selected.value = new Set(plan.value.recommended_keys);
};

const selectNone = () => {
    selected.value = new Set();
};

const apply = async () => {
    busy.value = true;

    try {
        const payload = await api.post(endpoint('import'), {
            keys: [...selected.value],
            scan_id: scanId.value ?? undefined,
        });

        if (payload.status === 'pending') {
            // The scan we showed expired between page-load and this click (or
            // never finished); it started a new one. Poll it and let the
            // operator hit Import again once the plan is back.
            applyScanResponse(payload);
            poller.start();
            flash('Still scanning the tree — try Import again once the plan reloads.', 'info');

            return;
        }

        flash(payload.message);
        payload.summary?.slice(0, 12).forEach((line) => flash(line, 'info'));

        await refreshSession();
        await load(true);
    } catch (thrown) {
        flashError(thrown);
    } finally {
        busy.value = false;
    }
};

</script>

<template>
    <div class="space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Import from disk</h1>
                <p class="mt-1.5 max-w-prose text-sm text-pretty text-fg-muted">
                    Reads
                    <code class="rounded-sm bg-sunken px-1 py-0.5 font-mono text-xs">{{
                        plan?.root ?? session.settings.staging_path
                    }}</code>
                    on
                    <code class="rounded-sm bg-sunken px-1 py-0.5 font-mono text-xs">{{
                        session.settings.staging_host
                    }}</code>
                    and fills the registry in from what is actually there. Nothing is written until you apply the
                    plan.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <AppButton variant="ghost" @click="manualMode = !manualMode">
                    {{ manualMode ? 'Cancel' : 'Paste JSON instead' }}
                </AppButton>
                <AppButton
                    icon="refresh"
                    :loading="loading || scanning"
                    :disabled="busy"
                    @click="load(true)"
                >
                    Re-scan
                </AppButton>
            </div>
        </header>

        <CardPanel
            v-if="manualMode"
            title="Paste a tree-scan result"
            subtitle="For when the scan above can't reach the fleet. Run mwdeploy-shim tree-scan --root <path> [...] wherever you can reach the tree, and paste its stdout below."
        >
            <div class="space-y-3">
                <div v-if="manualError" class="rounded-md border border-danger-line bg-danger-surface px-3 py-2 text-sm text-danger-text">
                    {{ manualError }}
                </div>

                <div>
                    <label class="label-caps block">
                        Root path
                    </label>
                    <input
                        v-model="manualRoot"
                        type="text"
                        :placeholder="session.settings.staging_path"
                        class="input-control mt-1 block w-full max-w-sm font-mono"
                    />
                    <p class="mt-1 text-xs text-fg-subtle">
                        Only needed if the pasted JSON has no <code class="font-mono">root</code> field of its own.
                    </p>
                </div>

                <div>
                    <label class="label-caps block">
                        tree-scan JSON
                    </label>
                    <textarea
                        v-model="manualPayload"
                        rows="10"
                        spellcheck="false"
                        placeholder='{"root": "...", "entries": [...]}'
                        class="input-control mt-1 block w-full font-mono"
                    />
                </div>

                <button
                    type="button"
                    class="btn btn-primary disabled:opacity-40"
                    :disabled="manualBusy || manualPayload.trim() === ''"
                    @click="submitManual"
                >
                    {{ manualBusy ? 'Reading…' : 'Build plan from this JSON' }}
                </button>
            </div>
        </CardPanel>

        <LoadState :loading="loading" :error="error" @retry="load">
            <div
                v-if="scanning && !plan"
                class="flex items-center gap-2 rounded-md border border-line bg-surface px-4 py-6 text-sm text-fg-subtle"
            >
                <span class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-line-strong border-t-slate-700" />
                Scanning <code class="font-mono">{{ session.settings.staging_path }}</code> on
                <code class="font-mono">{{ session.settings.staging_host }}</code>… a large farm can take a while;
                this page keeps checking on it.
            </div>

            <div v-else-if="plan" class="space-y-4">
                <!-- One strip, same as the overview's: four readings of the same
                     scan, so the divider groups them instead of four borders
                     separating them. -->
                <div class="panel grid divide-line sm:grid-cols-2 sm:divide-x lg:grid-cols-4">
                    <div class="p-5">
                        <p class="label-caps">On disk</p>
                        <p class="numeric mt-1.5 text-2xl font-semibold">
                            {{ Object.values(plan.scan_counts).reduce((total, count) => total + count, 0) }}
                        </p>
                        <p class="mt-0.5 text-xs text-fg-subtle">
                            {{ pluralise(plan.versions_on_disk.length, 'version') }}:
                            {{ plan.versions_on_disk.join(', ') || 'none' }}
                        </p>
                    </div>
                    <div class="border-t border-line p-5 sm:border-t-0">
                        <p class="label-caps">To import</p>
                        <p class="numeric mt-1.5 text-2xl font-semibold">{{ plan.actionable_count }}</p>
                        <p class="numeric mt-0.5 text-xs text-fg-subtle">{{ selected.size }} selected</p>
                    </div>
                    <div class="border-t border-line p-5 lg:border-t-0">
                        <p class="label-caps">Already in sync</p>
                        <p class="numeric mt-1.5 text-2xl font-semibold">{{ inSync.length }}</p>
                    </div>
                    <div class="border-t border-line p-5 lg:border-t-0">
                        <p class="label-caps">Cannot import</p>
                        <p
                            class="numeric mt-1.5 flex items-center gap-1.5 text-2xl font-semibold"
                            :class="blocked.length > 0 ? 'text-danger-text' : ''"
                        >
                            <AppIcon v-if="blocked.length > 0" name="error" class="size-5 shrink-0" />
                            {{ blocked.length }}
                        </p>
                        <p class="mt-0.5 text-xs text-pretty text-fg-subtle">no git remote to deploy from</p>
                    </div>
                </div>

                <div
                    v-if="plan.warnings.length > 0"
                    class="rounded-md border border-warning-line bg-warning-surface px-4 py-3 text-sm text-warning-text"
                >
                    <p class="font-medium">{{ pluralise(plan.warnings.length, 'thing') }} worth a look:</p>
                    <ul class="mt-1 max-h-40 list-disc space-y-0.5 overflow-auto ps-5 text-xs">
                        <li v-for="warning in plan.warnings" :key="warning">{{ warning }}</li>
                    </ul>
                </div>

                <CardPanel
                    title="Proposed changes"
                    subtitle="Registry only — nothing here clones, checks out or removes anything on disk."
                    flush
                >
                    <template #actions>
                        <button type="button" class="link-quiet" @click="selectRecommended">
                            Recommended
                        </button>
                        <button type="button" class="link-quiet" @click="selectAll">All</button>
                        <button type="button" class="link-quiet" @click="selectNone">None</button>
                    </template>

                    <p v-if="actionable.length === 0" class="px-5 py-6 text-sm text-fg-subtle">
                        The registry already describes this tree. Nothing to import.
                    </p>

                    <table v-else class="w-full text-sm">
                        <thead class="label-caps border-b border-line text-start">
                            <tr>
                                <th class="px-5 py-2"><span class="sr-only">Select</span></th>
                                <th class="px-5 py-2">Action</th>
                                <th class="px-5 py-2">What</th>
                                <th class="px-5 py-2">Ref on disk</th>
                                <th class="px-5 py-2">Path</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr
                                v-for="entry in actionable"
                                :key="entry.key"
                                class="align-top"
                                :class="isSelected(entry.key) ? 'bg-sunken' : ''"
                            >
                                <td class="px-5 py-2">
                                    <input
                                        type="checkbox"
                                        class="mt-0.5 size-4 rounded border-line-strong"
                                        :checked="isSelected(entry.key)"
                                        @change="toggle(entry.key)"
                                    />
                                </td>
                                <td class="px-5 py-2">
                                    <StatusBadge :label="entry.action_label" :tone="entry.badge_tone" />
                                </td>
                                <td class="px-5 py-2">
                                    <p class="font-medium">
                                        {{ entry.name }}
                                        <span v-if="entry.version" class="text-fg-subtle">({{ entry.version }})</span>
                                    </p>
                                    <p class="text-xs text-fg-subtle">{{ entry.summary }}</p>
                                    <p v-if="entry.manifest_name" class="text-xs text-fg-subtle">
                                        extension.json calls this “{{ entry.manifest_name }}”
                                        <template v-if="entry.manifest?.version">
                                            v{{ entry.manifest.version }}
                                        </template>
                                    </p>
                                    <p v-if="entry.note" class="mt-1 text-xs text-warning-text">{{ entry.note }}</p>
                                </td>
                                <td class="px-5 py-2 font-mono text-xs">
                                    {{ shortRef(entry.ref) }}
                                    <span v-if="entry.commit && entry.ref !== entry.commit" class="block text-fg-subtle">
                                        {{ shortRef(entry.commit) }}
                                    </span>
                                </td>
                                <td class="px-5 py-2 font-mono text-xs text-fg-subtle">{{ entry.path }}</td>
                            </tr>
                        </tbody>
                    </table>
                </CardPanel>

                <div class="flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        class="btn btn-primary disabled:opacity-40"
                        :disabled="busy || scanning || selected.size === 0"
                        @click="apply"
                    >
                        {{ busy ? 'Importing…' : `Import ${pluralise(selected.size, 'change')}` }}
                    </button>
                    <p class="text-xs text-fg-subtle">
                        Imported checkouts are recorded as already deployed, pinned to the ref they are on. No
                        deployment is queued.
                    </p>
                </div>

                <div v-if="blocked.length > 0">
                    <CardPanel
                        title="Cannot be imported"
                        subtitle="On disk, but with no git remote — nothing in the registry could describe how to update or restore them."
                        flush
                    >
                        <ul class="divide-y divide-line text-sm">
                            <li v-for="entry in blocked" :key="entry.key" class="px-5 py-2">
                                <p class="font-medium">{{ entry.name }}</p>
                                <p class="text-xs text-fg-subtle">{{ entry.summary }}</p>
                                <code class="font-mono text-xs text-fg-subtle">{{ entry.path }}</code>
                            </li>
                        </ul>
                    </CardPanel>
                </div>

                <div v-if="inSync.length > 0">
                    <button type="button" class="link-quiet text-sm" @click="showInSync = !showInSync">
                        {{ showInSync ? 'Hide' : 'Show' }} the {{ pluralise(inSync.length, 'checkout') }} already in sync
                    </button>

                    <CardPanel v-if="showInSync" class="mt-2" flush>
                        <ul class="divide-y divide-line text-sm">
                            <li v-for="entry in inSync" :key="entry.key" class="flex flex-wrap gap-2 px-5 py-1.5">
                                <span>{{ entry.name }}</span>
                                <span v-if="entry.version" class="text-fg-subtle">({{ entry.version }})</span>
                                <code class="ms-auto font-mono text-xs text-fg-subtle">{{ shortRef(entry.ref) }}</code>
                            </li>
                        </ul>
                    </CardPanel>
                </div>

                <p class="text-xs text-fg-subtle">
                    Config lives outside the version trees. If the tree has one and it is not registered yet, the
                    <RouterLink to="/deployments/repositories/config" class="link">config repository screen</RouterLink>
                    will adopt it in one step.
                </p>
            </div>
        </LoadState>
    </div>
</template>
