<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { pluralise } from '../../../format';

import { ApiError, api, endpoint } from '../../../api';
import AppButton from '../../../components/AppButton.vue';
import CardPanel from '../../../components/CardPanel.vue';
import FormField from '../../../components/FormField.vue';
import LoadState from '../../../components/LoadState.vue';
import ModalDialog from '../../../components/ModalDialog.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
import { can, flash, flashError } from '../../../store';

/**
 * MediaWiki core versions: what exists, cutting a new one, removing one.
 *
 * Cutting a version is the headline feature over the old CLI — reconstructing a
 * whole tree of ~100 extensions used to be a hundred manual clones.
 */
const router = useRouter();

const data = ref(null);
const loading = ref(true);
const error = ref(null);
const busy = ref(false);

const showCreate = ref(false);
const createErrors = ref({});
const form = ref({
    version: '',
    source_id: null,
    core_ref: '',
    parallel: 1,
    staging_only: true,
    rollout: false,
    l10n: false,
});

const removing = ref(null);
const removeForm = ref({ confirm_version: '', rollout: false });
const removeErrors = ref({});

const load = async () => {
    loading.value = true;

    try {
        data.value = await api.get(endpoint('versions'));
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

const versions = computed(() => data.value?.data ?? []);

const openCreate = () => {
    const newest = versions.value[0] ?? null;

    form.value = {
        version: '',
        source_id: newest?.id ?? null,
        // A sensible starting point: the release branch for the version being cut.
        core_ref: '',
        parallel: 1,
        staging_only: true,
        rollout: false,
        l10n: false,
    };
    createErrors.value = {};
    showCreate.value = true;
};

/** REL1_46 for 1.46 — the branch name MediaWiki actually uses. */
const suggestedRef = computed(() =>
    /^[0-9]+\.[0-9]+$/.test(form.value.version) ? `REL${form.value.version.replace('.', '_')}` : '',
);

const create = async () => {
    busy.value = true;
    createErrors.value = {};

    try {
        const payload = await api.post(endpoint('versions'), {
            ...form.value,
            core_ref: form.value.core_ref || suggestedRef.value,
        });

        flash(payload.message);
        showCreate.value = false;
        router.push(`/deployments/${payload.deployment_id}`);
    } catch (thrown) {
        if (thrown instanceof ApiError && thrown.isValidation) {
            createErrors.value = thrown.errors;
        } else {
            flashError(thrown);
        }
    } finally {
        busy.value = false;
    }
};

const openRemove = (version) => {
    removing.value = version;
    removeForm.value = { confirm_version: '', rollout: false };
    removeErrors.value = {};
};

const remove = async () => {
    busy.value = true;
    removeErrors.value = {};

    try {
        const payload = await api.post(
            endpoint(`versions/${removing.value.id}/undeploy`),
            removeForm.value,
        );

        flash(payload.message);
        removing.value = null;
        router.push(`/deployments/${payload.deployment_id}`);
    } catch (thrown) {
        if (thrown instanceof ApiError && thrown.isValidation) {
            removeErrors.value = thrown.errors;
        } else {
            flashError(thrown);
        }
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div class="space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Core versions</h1>
                <p class="mt-1.5 max-w-prose text-sm text-pretty text-fg-muted">
                    Each one is a whole MediaWiki tree on disk. Cutting a version reconstructs it from another —
                    core at the ref you give, every extension and skin at the pin it already had.
                </p>
            </div>
            <AppButton v-if="data?.can?.create" variant="primary" icon="plus" @click="openCreate">
                Cut a new version
            </AppButton>
        </header>

        <LoadState
            :loading="loading"
            :error="error"
            :empty="versions.length === 0"
            empty-title="No core versions are registered"
            empty-message="A core version is one MediaWiki tree — core, plus every extension and skin pinned to a ref. If versions/ already exists on disk, read the tree instead of cutting a new one."
            :skeleton-rows="3"
            @retry="load"
        >
            <template #empty-action>
                <AppButton v-if="can('manage_repositories')" to="/deployments/import" variant="primary">
                    Scan the tree
                </AppButton>
                <AppButton v-if="data?.can?.create" @click="openCreate">Cut a new version</AppButton>
            </template>

            <div class="grid gap-4 lg:grid-cols-2">
                <CardPanel v-for="version in versions" :key="version.id">
                    <div class="flex flex-wrap items-center gap-2">
                        <RouterLink :to="`/deployments/versions/${version.id}`" class="link text-base font-semibold">
                            {{ version.version }}
                        </RouterLink>
                        <StatusBadge :label="version.status_label" :tone="version.status_tone" />
                        <span v-if="version.imported" class="rounded bg-sunken px-1.5 py-0.5 text-xs text-fg-muted">
                            imported
                        </span>
                        <button
                            v-if="version.can.undeploy && version.present"
                            type="button"
                            class="ms-auto inline-flex min-h-8 items-center rounded-md px-2 text-xs font-medium text-danger-text hover:bg-danger-surface"
                            @click="openRemove(version)"
                        >
                            Undeploy this version
                        </button>
                    </div>

                    <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="label-caps">Tree</dt>
                            <dd><code class="font-mono text-xs">{{ version.path }}</code></dd>
                        </div>
                        <div>
                            <dt class="label-caps">MW_VERSION on disk</dt>
                            <dd>
                                <code v-if="version.core_version" class="font-mono text-xs">{{ version.core_version }}</code>
                                <span v-else class="text-xs text-fg-subtle">not scanned</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="label-caps">Contents</dt>
                            <dd>
                                {{ version.checkout_counts?.extension ?? 0 }} extensions,
                                {{ version.checkout_counts?.skin ?? 0 }} skins
                            </dd>
                        </div>
                        <div>
                            <dt class="label-caps">Cut from</dt>
                            <dd>{{ version.created_from ?? '—' }}</dd>
                        </div>
                    </dl>
                </CardPanel>
            </div>
        </LoadState>

        <ModalDialog
            v-if="showCreate"
            title="Cut a new core version"
            subtitle="Scaffolds the tree, then clones core plus every extension and skin the source version has."
            wide
            @close="showCreate = false"
        >
            <div class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="New version" required :error="createErrors.version?.[0]" hint="Like 1.46." v-slot="field">
                        <input v-bind="field"
                            v-model="form.version"
                            type="text"
                            placeholder="1.46"
                            class="input-control block w-full font-mono"
                        />
                    </FormField>

                    <FormField
                        label="Reconstruct from"
                        hint="Every extension and skin present in this version is cloned into the new one."
                     v-slot="field">
                        <select v-bind="field"
                            v-model="form.source_id"
                            class="input-control block w-full"
                        >
                            <option :value="null">Nothing — core only</option>
                            <option v-for="version in versions" :key="version.id" :value="version.id">
                                {{ version.version }}
                                ({{ version.checkout_counts?.total ?? 0 }} checkouts)
                            </option>
                        </select>
                    </FormField>
                </div>

                <FormField
                    label="Core ref"
                    required
                    :error="createErrors.core_ref?.[0]"
                    :hint="suggestedRef ? `Leave blank to use ${suggestedRef}.` : 'Branch or tag to check core out at.'"
                 v-slot="field">
                    <input v-bind="field"
                        v-model="form.core_ref"
                        type="text"
                        :placeholder="suggestedRef || 'REL1_46'"
                        class="input-control block w-full font-mono"
                    />
                </FormField>

                <p class="text-xs text-fg-subtle">
                    Each extension and skin is cloned at its own pin from the source version, so a repository
                    tracking REL1_45 there arrives here on the ref you have pinned for it.
                </p>

                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.staging_only" type="checkbox" class="mt-1 size-4 rounded border-line-strong" />
                    <span>
                        <span class="font-medium">Build on staging only</span>
                        <span class="block text-xs text-fg-subtle">
                            A brand new version serves no traffic yet: build it, check it, then roll it out as a
                            separate deployment.
                        </span>
                    </span>
                </label>

                <label class="flex items-start gap-2 text-sm">
                    <input v-model="form.l10n" type="checkbox" class="mt-1 size-4 rounded border-line-strong" />
                    <span class="font-medium">Rebuild the l10n cache afterwards</span>
                </label>
            </div>

            <template #footer>
                <button
                    type="button"
                    class="btn btn-secondary"
                    @click="showCreate = false"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="busy || !form.version"
                    @click="create"
                >
                    {{ busy ? 'Queueing…' : 'Build it' }}
                </button>
            </template>
        </ModalDialog>

        <ModalDialog
            v-if="removing"
            :title="`Undeploy ${removing.version}?`"
            subtitle="This deletes the entire version tree from staging and from every server."
            danger
            @close="removing = null"
        >
            <div class="space-y-4">
                <p class="text-sm">
                    <code class="font-mono">rm -rf {{ removing.path }}</code> runs once per host.
                    {{ pluralise(removing.checkout_counts?.total ?? 0, 'checkout') }} go with it.
                </p>

                <p class="rounded-md bg-danger-surface px-3 py-2 text-sm text-danger-text">
                    This app does not check whether any wiki still points at {{ removing.version }} before
                    removing it — confirm that separately before continuing.
                </p>

                <FormField
                    :label="`Type ${removing.version} to confirm`"
                    required
                    :error="removeErrors.confirm_version?.[0]"
                 v-slot="field">
                    <input v-bind="field"
                        v-model="removeForm.confirm_version"
                        type="text"
                        class="input-control block w-full border-danger-line font-mono"
                    />
                </FormField>

                <label class="flex items-start gap-2 text-sm">
                    <input v-model="removeForm.rollout" type="checkbox" class="mt-1 size-4 rounded border-line-strong" />
                    <span>
                        <span class="font-medium">Depool each server first</span>
                        <span class="block text-xs text-fg-subtle">Recommended when the version is still pooled.</span>
                    </span>
                </label>
            </div>

            <template #footer>
                <button type="button" class="btn btn-secondary" @click="removing = null">
                    Cancel
                </button>
                <button
                    type="button"
                    class="btn btn-danger"
                    :disabled="busy || removeForm.confirm_version !== removing.version"
                    @click="remove"
                >
                    {{ busy ? 'Queueing…' : `Undeploy ${removing.version}` }}
                </button>
            </template>
        </ModalDialog>
    </div>
</template>
