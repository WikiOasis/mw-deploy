<script setup>
import { computed, onMounted, ref } from 'vue';

import { ApiError, api, endpoint } from '../api';
import CardPanel from '../components/CardPanel.vue';
import FormField from '../components/FormField.vue';
import LoadState from '../components/LoadState.vue';
import ModalDialog from '../components/ModalDialog.vue';
import { relative } from '../format';
import { flash, flashError } from '../store';

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
        <header class="flex flex-wrap items-center gap-3">
            <h1 class="text-lg font-semibold tracking-tight">Patches</h1>
            <button
                v-if="data?.can?.manage"
                type="button"
                class="ml-auto rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700"
                @click="open()"
            >
                Register a patch
            </button>
        </header>

        <LoadState
            :loading="loading"
            :error="error"
            :empty="patches.length === 0"
            empty-message="No patches are registered."
            @retry="load"
        >
            <CardPanel flush>
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 text-left text-xs tracking-wide text-slate-500 uppercase">
                        <tr>
                            <th class="px-5 py-2">Patch</th>
                            <th class="px-5 py-2">Applies to</th>
                            <th class="px-5 py-2">Last dry run</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="patch in patches" :key="patch.id" class="align-top">
                            <td class="px-5 py-2">
                                <p class="font-medium" :class="patch.active ? '' : 'text-slate-400 line-through'">
                                    {{ patch.name }}
                                </p>
                                <p v-if="patch.description" class="text-xs text-slate-500">{{ patch.description }}</p>
                                <p class="font-mono text-xs text-slate-400">{{ patch.original_filename }}</p>
                            </td>
                            <td class="px-5 py-2">
                                <p>{{ patch.target_label }}</p>
                                <code class="font-mono text-xs text-slate-500">{{ patch.target_path }}</code>
                            </td>
                            <td class="px-5 py-2">
                                <template v-if="patch.last_checked_at">
                                    <span :class="patch.last_check_ok ? 'text-emerald-700' : 'text-rose-700'">
                                        {{ patch.last_check_ok ? 'applies cleanly' : 'failed dry run' }}
                                    </span>
                                    <span class="block text-xs text-slate-400">
                                        {{ relative(patch.last_checked_at) }}
                                    </span>
                                    <pre
                                        v-if="!patch.last_check_ok && patch.last_check_detail"
                                        class="mt-1 max-h-24 overflow-auto rounded bg-slate-50 p-2 font-mono text-xs text-slate-600"
                                        >{{ patch.last_check_detail }}</pre
                                    >
                                </template>
                                <span v-else class="text-xs text-slate-400">never checked</span>
                            </td>
                            <td class="px-5 py-2 text-right text-sm whitespace-nowrap">
                                <button
                                    v-if="patch.can.check"
                                    type="button"
                                    class="text-slate-600 underline disabled:opacity-50"
                                    :disabled="busy"
                                    @click="check(patch)"
                                >
                                    Dry run
                                </button>
                                <button
                                    v-if="patch.can.manage"
                                    type="button"
                                    class="ml-3 text-slate-600 underline"
                                    @click="open(patch)"
                                >
                                    Edit
                                </button>
                                <button
                                    v-if="patch.can.manage && patch.active"
                                    type="button"
                                    class="ml-3 text-rose-700 underline disabled:opacity-50"
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
                <FormField label="Name" required :error="errors.name?.[0]">
                    <input
                        v-model="form.name"
                        type="text"
                        class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                    />
                </FormField>

                <FormField label="Description" :error="errors.description?.[0]">
                    <textarea
                        v-model="form.description"
                        rows="2"
                        class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                    />
                </FormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Target checkout"
                        :error="errors.target_repository_version_id?.[0]"
                        hint="A patch is written against one core version's files."
                    >
                        <select
                            v-model="form.target_repository_version_id"
                            class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
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
                    >
                        <input
                            v-model="form.target_path"
                            type="text"
                            class="block w-full rounded-md bg-white px-3 py-2 font-mono text-sm ring-1 ring-inset ring-slate-300"
                        />
                    </FormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="Format" :error="errors.format?.[0]">
                        <select
                            v-model="form.format"
                            class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
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
                    >
                        <input
                            type="file"
                            class="block w-full text-sm"
                            @change="file = $event.target.files[0] ?? null"
                        />
                    </FormField>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.active" type="checkbox" class="rounded border-slate-300" />
                    Active — offered on deployments touching its repository
                </label>
            </div>

            <template #footer>
                <button type="button" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-slate-300" @click="editing = null">
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50"
                    :disabled="busy || !form.name"
                    @click="submit"
                >
                    {{ busy ? 'Saving…' : 'Save' }}
                </button>
            </template>
        </ModalDialog>
    </div>
</template>
