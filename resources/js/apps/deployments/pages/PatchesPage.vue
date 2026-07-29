<script setup>
import { computed, onMounted, ref } from 'vue';

import { ApiError, api, endpoint } from '../../../api';
import AppButton from '../../../components/AppButton.vue';
import CardPanel from '../../../components/CardPanel.vue';
import FormField from '../../../components/FormField.vue';
import LoadState from '../../../components/LoadState.vue';
import ModalDialog from '../../../components/ModalDialog.vue';
import { relative } from '../../../format';
import { flash, flashError } from '../../../store';

/**
 * The patch registry.
 *
 * The point of storing the target directory on the patch row is that nobody
 * retypes it at deploy time — which is the class of mistake the CLI's
 * `--patch`/`--patch-target` pair invited. The dry-run button is the other half:
 * an upstream update that breaks a patch should be found here, not mid-deploy.
 */
const data = ref(null);
const loading = ref(true);
const error = ref(null);
const busy = ref(false);
const errors = ref({});

const editing = ref(null);
const file = ref(null);
const form = ref({
    name: '',
    description: '',
    target_repository_version_id: '',
    target_path: '',
    format: 'unified',
    active: true,
});

const load = async () => {
    loading.value = true;

    try {
        data.value = await api.get(endpoint('patches'));
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

const patches = computed(() => data.value?.data ?? []);

const open = (patch = null) => {
    editing.value = patch ?? { id: null };
    errors.value = {};
    file.value = null;

    form.value = patch
        ? {
              name: patch.name,
              description: patch.description ?? '',
              target_repository_version_id: patch.target_checkout_id ?? '',
              target_path: patch.target_path,
              format: patch.format,
              active: patch.active,
          }
        : {
              name: '',
              description: '',
              target_repository_version_id: '',
              target_path: '',
              format: 'unified',
              active: true,
          };
};

/**
 * Choosing a checkout fills the target path in from it, which is the whole reason
 * the registry stores the target: the patch and the directory it applies to travel
 * together.
 */
const onCheckoutChange = () => {
    const checkout = (data.value?.checkouts ?? []).find(
        (option) => option.id === Number(form.value.target_repository_version_id),
    );

    if (checkout && form.value.target_path === '') {
        form.value.target_path = checkout.path;
    }
};

const submit = async () => {
    busy.value = true;
    errors.value = {};

    const body = new FormData();

    Object.entries(form.value).forEach(([key, value]) => {
        if (value === null || value === '') {
            return;
        }

        body.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value);
    });

    if (file.value) {
        body.append('patch_file', file.value);
    }

    try {
        const url = editing.value.id ? endpoint(`patches/${editing.value.id}`) : endpoint('patches');
        const payload = await api.post(url, body);

        flash(payload.message);
        editing.value = null;
        await load();
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

const check = async (patch) => {
    busy.value = true;

    try {
        const payload = await api.post(endpoint(`patches/${patch.id}/check`), {});

        flash(payload.message, payload.ok ? 'success' : 'error');
        await load();
    } catch (thrown) {
        flashError(thrown);
    } finally {
        busy.value = false;
    }
};

const deactivate = async (patch) => {
    busy.value = true;

    try {
        const payload = await api.delete(endpoint(`patches/${patch.id}`));

        flash(payload.message);
        await load();
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
                <h1 class="text-xl font-semibold">Patches</h1>
                <p class="mt-1.5 max-w-prose text-sm text-pretty text-fg-muted">
                    Local changes this farm carries on top of a repository, reapplied after every deployment of it.
                </p>
            </div>
            <AppButton v-if="data?.can?.manage" variant="primary" icon="plus" @click="open()">
                Register a patch
            </AppButton>
        </header>

        <LoadState
            :loading="loading"
            :error="error"
            :empty="patches.length === 0"
            empty-title="No patches are registered"
            empty-message="A patch is a local change this farm carries on top of a repository — reapplied every time that repository is deployed, so an upgrade does not quietly drop it."
            :skeleton-rows="4"
            @retry="load"
        >
            <template #empty-action>
                <AppButton v-if="data?.can?.manage" variant="primary" icon="plus" @click="open()">
                    Register a patch
                </AppButton>
            </template>

            <CardPanel flush>
                <table class="w-full text-sm">
                    <thead class="label-caps border-b border-line text-start">
                        <tr>
                            <th class="px-5 py-2">Patch</th>
                            <th class="px-5 py-2">Applies to</th>
                            <th class="px-5 py-2">Last dry run</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="patch in patches" :key="patch.id" class="align-top">
                            <td class="px-5 py-2">
                                <p class="font-medium" :class="patch.active ? '' : 'text-fg-subtle line-through'">
                                    {{ patch.name }}
                                </p>
                                <p v-if="patch.description" class="text-xs text-fg-subtle">{{ patch.description }}</p>
                                <p class="font-mono text-xs text-fg-subtle">{{ patch.original_filename }}</p>
                            </td>
                            <td class="px-5 py-2">
                                <p>{{ patch.target_label }}</p>
                                <code class="font-mono text-xs text-fg-subtle">{{ patch.target_path }}</code>
                            </td>
                            <td class="px-5 py-2">
                                <template v-if="patch.last_checked_at">
                                    <span :class="patch.last_check_ok ? 'text-success-text' : 'text-danger-text'">
                                        {{ patch.last_check_ok ? 'applies cleanly' : 'failed dry run' }}
                                    </span>
                                    <span class="block text-xs text-fg-subtle">
                                        {{ relative(patch.last_checked_at) }}
                                    </span>
                                    <pre
                                        v-if="!patch.last_check_ok && patch.last_check_detail"
                                        class="mt-1 max-h-24 overflow-auto rounded bg-sunken p-2 font-mono text-xs text-fg-muted"
                                        >{{ patch.last_check_detail }}</pre
                                    >
                                </template>
                                <span v-else class="text-xs text-fg-subtle">never checked</span>
                            </td>
                            <td class="px-5 py-2 text-end text-sm whitespace-nowrap">
                                <button
                                    v-if="patch.can.check"
                                    type="button"
                                    class="link-quiet disabled:opacity-50"
                                    :disabled="busy"
                                    @click="check(patch)"
                                >
                                    Dry run
                                </button>
                                <button
                                    v-if="patch.can.manage"
                                    type="button"
                                    class="link-quiet ms-3"
                                    @click="open(patch)"
                                >
                                    Edit
                                </button>
                                <button
                                    v-if="patch.can.manage && patch.active"
                                    type="button"
                                    class="ms-3 inline-flex min-h-8 items-center rounded-md px-2 text-danger-text hover:bg-danger-surface disabled:opacity-50"
                                    :disabled="busy"
                                    @click="deactivate(patch)"
                                >
                                    Deactivate
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardPanel>
        </LoadState>

        <ModalDialog
            v-if="editing"
            :title="editing.id ? `Edit ${form.name}` : 'Register a patch'"
            subtitle="Stored with its target directory, so it is never retyped at deploy time."
            wide
            @close="editing = null"
        >
            <div class="space-y-4">
                <FormField label="Name" required :error="errors.name?.[0]" v-slot="field">
                    <input v-bind="field"
                        v-model="form.name"
                        type="text"
                        class="input-control block w-full"
                    />
                </FormField>

                <FormField label="Description" :error="errors.description?.[0]" v-slot="field">
                    <textarea v-bind="field"
                        v-model="form.description"
                        rows="2"
                        class="input-control block w-full"
                    />
                </FormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Target checkout"
                        :error="errors.target_repository_version_id?.[0]"
                        hint="A patch is written against one core version's files."
                     v-slot="field">
                        <select v-bind="field"
                            v-model="form.target_repository_version_id"
                            class="input-control block w-full"
                            @change="onCheckoutChange"
                        >
                            <option value="">Freeform — no checkout</option>
                            <option v-for="checkout in data.checkouts" :key="checkout.id" :value="checkout.id">
                                {{ checkout.display_name }} — {{ checkout.path }}
                            </option>
                        </select>
                    </FormField>

                    <FormField
                        label="Target path"
                        required
                        :error="errors.target_path?.[0]"
                        hint="Relative to the deploy root; the directory the patch is applied in."
                     v-slot="field">
                        <input v-bind="field"
                            v-model="form.target_path"
                            type="text"
                            class="input-control block w-full font-mono"
                        />
                    </FormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Format" :error="errors.format?.[0]" v-slot="field">
                        <select v-bind="field"
                            v-model="form.format"
                            class="input-control block w-full"
                        >
                            <option value="unified">unified (patch -p1)</option>
                            <option value="git">git (git apply)</option>
                        </select>
                    </FormField>

                    <FormField
                        label="Patch file"
                        :required="!editing.id"
                        :error="errors.patch_file?.[0]"
                        hint="Replacing the file clears the previous dry-run verdict."
                     v-slot="field">
                        <input v-bind="field"
                            type="file"
                            class="block w-full text-sm"
                            @change="file = $event.target.files[0] ?? null"
                        />
                    </FormField>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.active" type="checkbox" class="size-4 rounded border-line-strong" />
                    Active — offered on deployments touching its repository
                </label>
            </div>

            <template #footer>
                <button type="button" class="btn btn-secondary" @click="editing = null">
                    Cancel
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="busy || !form.name"
                    @click="submit"
                >
                    {{ busy ? 'Saving…' : 'Save' }}
                </button>
            </template>
        </ModalDialog>
    </div>
</template>
