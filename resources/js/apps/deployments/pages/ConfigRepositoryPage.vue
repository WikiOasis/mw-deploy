<script setup>
import { onMounted, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';

import { ApiError, api, endpoint } from '../../../api';
import CardPanel from '../../../components/CardPanel.vue';
import FormField from '../../../components/FormField.vue';
import LoadState from '../../../components/LoadState.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
import { shortRef } from '../../../format';
import { flash, flashError, refreshSession, session } from '../../../store';

/**
 * Registering the config repository, in one field.
 *
 * mw-config is the repository every farm has exactly one of, always at the same
 * place in the tree, never versioned. The generic repository form asks four
 * questions that all have one possible answer here, so this screen asks for the git
 * URL and works the rest out — including whether the checkout is already on disk,
 * in which case it is adopted rather than cloned over.
 */
const router = useRouter();

const data = ref(null);
const loading = ref(true);
const error = ref(null);
const busy = ref(false);
const errors = ref({});

const form = ref({ git_url: '', default_branch: '', name: '' });

const load = async () => {
    loading.value = true;

    try {
        data.value = await api.get(endpoint('repositories/config'));

        // Pre-fill from the checkout that is already there: the remote it was
        // cloned from is almost certainly the right answer.
        if (data.value.on_disk?.git_url) {
            form.value.git_url = data.value.on_disk.git_url;
            form.value.default_branch = data.value.on_disk.default_branch ?? '';
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

const submit = async () => {
    busy.value = true;
    errors.value = {};

    try {
        const payload = await api.post(endpoint('repositories/config'), {
            git_url: form.value.git_url,
            default_branch: form.value.default_branch || null,
            name: form.value.name || null,
        });

        flash(payload.message);
        await refreshSession();

        if (payload.deployment_id) {
            router.push(`/deployments/${payload.deployment_id}`);
        } else {
            router.push(`/deployments/repositories/${payload.repository.id}`);
        }
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
        <header>
            <h1 class="text-xl font-semibold">Config repository</h1>
            <p class="mt-1.5 max-w-prose text-sm text-pretty text-fg-muted">
                Wiki config sits outside the version trees — one checkout serves every core version — at
                <code class="rounded-sm bg-sunken px-1 py-0.5 font-mono text-xs">{{
                    session.settings.config_dir
                }}</code>
                in the deploy root.
            </p>
        </header>

        <LoadState :loading="loading" :error="error" @retry="load">
            <div v-if="data" class="grid gap-4 lg:grid-cols-2">
                <CardPanel :title="data.repository ? 'Registered' : 'Not registered yet'">
                    <div v-if="data.repository" class="space-y-3 text-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <RouterLink :to="`/deployments/repositories/${data.repository.id}`" class="link font-medium">
                                {{ data.repository.name }}
                            </RouterLink>
                            <span v-if="data.repository.imported" class="rounded bg-sunken px-1.5 py-0.5 text-xs">
                                imported
                            </span>
                        </div>
                        <dl class="space-y-1">
                            <div>
                                <dt class="label-caps">Remote</dt>
                                <dd class="font-mono text-xs break-all">{{ data.repository.git_url }}</dd>
                            </div>
                            <div>
                                <dt class="label-caps">Default branch</dt>
                                <dd class="font-mono text-xs">{{ data.repository.default_branch }}</dd>
                            </div>
                        </dl>
                        <ul class="space-y-1">
                            <li v-for="checkout in data.repository.checkouts ?? []" :key="checkout.id" class="flex gap-2">
                                <StatusBadge :label="checkout.status_label" :tone="checkout.status_tone" />
                                <code class="font-mono text-xs">{{ checkout.path }}</code>
                                <code class="ms-auto font-mono text-xs">{{ checkout.resolved_ref }}</code>
                            </li>
                        </ul>
                        <p class="text-xs text-fg-subtle">
                            Deploying config is an ordinary deployment: pick it in the wizard like any other
                            repository. It needs <code class="font-mono">deploy.config</code>.
                        </p>
                    </div>

                    <p v-else class="text-sm text-fg-subtle">
                        Nothing of type <code class="font-mono">config</code> is registered, so this app cannot
                        deploy wiki config yet.
                    </p>
                </CardPanel>

                <CardPanel title="What is on disk" subtitle="From the last tree scan">
                    <div v-if="data.on_disk" class="space-y-2 text-sm">
                        <p>
                            <code class="font-mono text-xs">{{ data.on_disk.path }}</code>
                            <span v-if="!data.on_disk.is_git" class="ms-2 text-danger-text">not a git checkout</span>
                        </p>
                        <dl v-if="data.on_disk.is_git" class="space-y-1">
                            <div>
                                <dt class="label-caps">Remote</dt>
                                <dd class="font-mono text-xs break-all">{{ data.on_disk.git_url ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="label-caps">Currently on</dt>
                                <dd class="font-mono text-xs">
                                    {{ data.on_disk.ref ?? '—' }}
                                    <span v-if="data.on_disk.commit" class="text-fg-subtle">
                                        ({{ shortRef(data.on_disk.commit) }})
                                    </span>
                                </dd>
                            </div>
                        </dl>
                        <p v-if="data.on_disk.blocker" class="text-xs text-danger-text">{{ data.on_disk.blocker }}</p>
                        <p v-else class="text-xs text-success-text">
                            This checkout can be adopted as-is — registering will not clone over it.
                        </p>
                    </div>

                    <p v-else-if="data.scan_error" class="text-sm text-warning-text">
                        The tree could not be scanned, so this screen cannot tell you what is already there:
                        <span class="block font-mono text-xs">{{ data.scan_error }}</span>
                        Registering still works; it will clone onto staging.
                    </p>

                    <p v-else class="text-sm text-fg-subtle">
                        There is no <code class="font-mono">{{ data.config_dir }}</code> directory in the tree yet.
                        Registering will clone one onto staging.
                    </p>
                </CardPanel>

                <CardPanel
                    v-if="data.can_manage"
                    class="lg:col-span-2"
                    :title="data.repository ? 'Repoint it' : 'Add the config repository'"
                    subtitle="The git URL is the only thing that cannot be worked out."
                >
                    <div class="space-y-4">
                        <FormField
                            label="Git remote"
                            required
                            :error="errors.git_url?.[0]"
                            hint="https:// or git@host:path. Checked for reachability before anything is written."
                         v-slot="field">
                            <input v-bind="field"
                                v-model="form.git_url"
                                type="text"
                                placeholder="https://github.com/wikioasis/mw-config.git"
                                class="input-control block w-full font-mono"
                            />
                        </FormField>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField
                                label="Default branch"
                                :error="errors.default_branch?.[0]"
                                hint="Optional. Read from the checkout on disk, or the remote's HEAD."
                             v-slot="field">
                                <input v-bind="field"
                                    v-model="form.default_branch"
                                    type="text"
                                    :placeholder="data.on_disk?.default_branch ?? 'master'"
                                    class="input-control block w-full font-mono"
                                />
                            </FormField>

                            <FormField
                                label="Registry name"
                                :error="errors.name?.[0]"
                                hint="Optional. Defaults to the repository name in the URL."
                             v-slot="field">
                                <input v-bind="field"
                                    v-model="form.name"
                                    type="text"
                                    :placeholder="data.suggested_name"
                                    class="input-control block w-full"
                                />
                            </FormField>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                class="btn btn-primary disabled:opacity-40"
                                :disabled="busy || !form.git_url"
                                @click="submit"
                            >
                                {{
                                    busy
                                        ? 'Working…'
                                        : data.on_disk?.importable
                                          ? 'Adopt the checkout on disk'
                                          : 'Register and clone onto staging'
                                }}
                            </button>
                            <p class="text-xs text-fg-subtle">
                                <template v-if="data.on_disk?.importable">
                                    The directory already exists, so this only writes registry rows.
                                </template>
                                <template v-else>
                                    Cloning runs as an ordinary staging-only deployment you can review and roll back.
                                </template>
                            </p>
                        </div>
                    </div>
                </CardPanel>
            </div>
        </LoadState>
    </div>
</template>
