<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';

import { ApiError, api, endpoint } from '../api';
import CardPanel from '../components/CardPanel.vue';
import FormField from '../components/FormField.vue';
import LoadState from '../components/LoadState.vue';
import { flash, flashError, refreshSession, session } from '../store';

/**
 * Register a repository, or edit one.
 *
 * Registering writes the registry rows and queues a *staging-only* deployment to
 * clone them — so adding an extension is reviewable, appears on the dashboard, and
 * can be rolled back, which removes the checkouts again.
 *
 * Editing is metadata only. Checkout paths are deliberately immutable: they are
 * what `repo-remove` gets pointed at, and moving a live checkout is a filesystem
 * operation, not a form field.
 */
const props = defineProps({
    id: { type: [String, Number], default: null },
});

const router = useRouter();

const isEdit = computed(() => props.id !== null);

const loading = ref(true);
const error = ref(null);
const busy = ref(false);
const errors = ref({});
const versions = ref([]);
const existing = ref(null);

const form = ref({
    name: '',
    type: 'extension',
    git_url: '',
    default_branch: 'master',
    in_use: true,
    versions: [],
    refs: {},
});

const load = async () => {
    loading.value = true;

    try {
        const versionPayload = await api.get(endpoint('versions'));

        versions.value = (versionPayload.data ?? []).filter((version) => version.present);

        if (isEdit.value) {
            const payload = await api.get(endpoint(`repositories/${props.id}`));

            existing.value = payload.data;
            form.value = {
                ...form.value,
                name: payload.data.name,
                type: payload.data.type,
                git_url: payload.data.git_url,
                default_branch: payload.data.default_branch,
                in_use: payload.data.in_use,
            };
        }

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

const selectedType = computed(() =>
    (session.reference.repository_types ?? []).find((type) => type.value === form.value.type),
);

const needsVersions = computed(
    () => selectedType.value?.versioned === true && form.value.type !== 'core',
);

/** REL1_45 for the 1.45 checkout — the pin the operator almost always wants. */
const suggestPin = (version) => `REL${version.version.replace('.', '_')}`;

const toggleVersion = (version) => {
    const selected = form.value.versions.includes(version.id);

    form.value.versions = selected
        ? form.value.versions.filter((id) => id !== version.id)
        : [...form.value.versions, version.id];

    if (!selected && form.value.refs[version.id] === undefined) {
        form.value.refs[version.id] = { ref_mode: 'pinned', ref: suggestPin(version) };
    }
};

const submit = async () => {
    busy.value = true;
    errors.value = {};

    const payload = {
        name: form.value.name,
        type: form.value.type,
        git_url: form.value.git_url,
        default_branch: form.value.default_branch,
        in_use: form.value.in_use,
        versions: needsVersions.value ? form.value.versions : [],
        refs: needsVersions.value ? form.value.refs : {},
    };

    try {
        if (isEdit.value) {
            const result = await api.put(endpoint(`repositories/${props.id}`), payload);

            flash(result.message);
            router.push(`/repositories/${props.id}`);

            return;
        }

        const result = await api.post(endpoint('repositories'), payload);

        flash(result.message);
        await refreshSession();

        router.push(
            result.deployment_id ? `/deployments/${result.deployment_id}` : `/repositories/${result.repository.id}`,
        );
    } catch (thrown) {
        if (thrown instanceof ApiError && thrown.isValidation) {
            errors.value = thrown.errors;
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
        <header class="flex flex-wrap items-baseline gap-3">
            <RouterLink to="/repositories" class="text-sm text-slate-500 underline">Repositories</RouterLink>
            <h1 class="text-lg font-semibold tracking-tight">
                {{ isEdit ? `Edit ${existing?.name ?? ''}` : 'Register a repository' }}
            </h1>
        </header>

        <LoadState :loading="loading" :error="error" @retry="load">
            <div class="grid gap-4 lg:grid-cols-3">
                <CardPanel class="lg:col-span-2" title="Repository">
                    <div class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField
                                label="Name"
                                required
                                :error="errors.name?.[0]"
                                hint="Becomes the directory name, e.g. Echo → extensions/Echo."
                            >
                                <input
                                    v-model="form.name"
                                    type="text"
                                    :disabled="isEdit"
                                    class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 disabled:bg-slate-50"
                                />
                            </FormField>

                            <FormField label="Type" required :error="errors.type?.[0]">
                                <select
                                    v-model="form.type"
                                    :disabled="isEdit"
                                    class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 disabled:bg-slate-50"
                                >
                                    <option
                                        v-for="type in session.reference.repository_types"
                                        :key="type.value"
                                        :value="type.value"
                                    >
                                        {{ type.label }}
                                    </option>
                                </select>
                            </FormField>
                        </div>

                        <FormField
                            label="Git remote"
                            required
                            :error="errors.git_url?.[0]"
                            hint="https:// or git@host:path. Reachability is checked before anything is written."
                        >
                            <input
                                v-model="form.git_url"
                                type="text"
                                placeholder="https://github.com/wikimedia/mediawiki-extensions-Echo.git"
                                class="block w-full rounded-md bg-white px-3 py-2 font-mono text-sm ring-1 ring-inset ring-slate-300"
                            />
                        </FormField>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField label="Default branch" required :error="errors.default_branch?.[0]">
                                <input
                                    v-model="form.default_branch"
                                    type="text"
                                    class="block w-full rounded-md bg-white px-3 py-2 font-mono text-sm ring-1 ring-inset ring-slate-300"
                                />
                            </FormField>

                            <label class="flex items-start gap-2 pt-6 text-sm">
                                <input v-model="form.in_use" type="checkbox" class="mt-1 rounded border-slate-300" />
                                <span>
                                    <span class="font-medium">In use by the farm</span>
                                    <span class="block text-xs text-slate-500">
                                        Informational: drives the "in use" filter.
                                    </span>
                                </span>
                            </label>
                        </div>

                        <div v-if="!isEdit && needsVersions">
                            <p class="text-sm font-medium text-slate-700">Add to these core versions</p>
                            <p class="text-xs text-slate-500">
                                Each checkout gets its own pin, which is what lets one repository track REL1_45 under
                                1.45 and REL1_46 under 1.46.
                            </p>
                            <p v-if="errors.versions?.[0]" class="mt-1 text-xs text-rose-600">
                                {{ errors.versions[0] }}
                            </p>

                            <ul class="mt-2 space-y-2">
                                <li
                                    v-for="version in versions"
                                    :key="version.id"
                                    class="rounded border border-slate-200 p-2"
                                >
                                    <label class="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            class="rounded border-slate-300"
                                            :checked="form.versions.includes(version.id)"
                                            @change="toggleVersion(version)"
                                        />
                                        <span class="font-medium">{{ version.version }}</span>
                                    </label>

                                    <div v-if="form.versions.includes(version.id)" class="mt-2 grid gap-2 pl-6 sm:grid-cols-2">
                                        <select
                                            v-model="form.refs[version.id].ref_mode"
                                            class="rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                                        >
                                            <option value="pinned">Pinned to a ref</option>
                                            <option value="default_branch">Repository's default branch</option>
                                            <option value="floating">Chosen each deployment</option>
                                        </select>
                                        <input
                                            v-model="form.refs[version.id].ref"
                                            type="text"
                                            :disabled="form.refs[version.id].ref_mode !== 'pinned'"
                                            class="rounded-md bg-white px-3 py-2 font-mono text-sm ring-1 ring-inset ring-slate-300 disabled:bg-slate-50"
                                        />
                                    </div>
                                </li>
                                <li v-if="versions.length === 0" class="text-sm text-slate-500">
                                    No core versions are registered yet. Register one, or import the tree.
                                </li>
                            </ul>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-40"
                                :disabled="busy || !form.name || !form.git_url"
                                @click="submit"
                            >
                                {{ busy ? 'Working…' : isEdit ? 'Save changes' : 'Register and clone' }}
                            </button>
                            <p v-if="!isEdit" class="text-xs text-slate-500">
                                Cloning lands on staging only. Rolling that deployment back removes the checkouts
                                again.
                            </p>
                        </div>
                    </div>
                </CardPanel>

                <CardPanel title="Notes">
                    <ul class="space-y-2 text-xs text-slate-600">
                        <li v-if="form.type === 'config'">
                            For the config repository there is a
                            <RouterLink to="/repositories/config" class="underline">one-field screen</RouterLink>
                            that also adopts an existing <code class="font-mono">{{ session.settings.config_dir }}</code>
                            checkout instead of cloning over it.
                        </li>
                        <li v-if="form.type === 'core'">
                            MediaWiki core is registered once. Its per-version checkouts come from cutting a version,
                            not from this form.
                        </li>
                        <li>
                            Already on disk? <RouterLink to="/import" class="underline">Import from disk</RouterLink>
                            adopts what is there instead of cloning it again.
                        </li>
                        <li v-if="isEdit">
                            Paths are immutable here on purpose: they are what a removal is pointed at.
                        </li>
                    </ul>
                </CardPanel>
            </div>
        </LoadState>
    </div>
</template>
