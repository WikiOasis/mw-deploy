<script setup>
import { computed, onMounted, ref } from 'vue';

import { ApiError, api, endpoint } from '../../../api';
import CardPanel from '../../../components/CardPanel.vue';
import FormField from '../../../components/FormField.vue';
import LoadState from '../../../components/LoadState.vue';
import ModalDialog from '../../../components/ModalDialog.vue';
import { flash, flashError } from '../../../store';

/**
 * The deploy target inventory, plus manual depool/repool.
 *
 * `hostname` must equal the Salt minion id exactly — that string is what gets
 * passed as the Salt target, so a typo here is a deployment that silently reaches
 * no host.
 */
const data = ref(null);
const loading = ref(true);
const error = ref(null);
const busy = ref(false);
const errors = ref({});
const editing = ref(null);

const form = ref({
    hostname: '',
    ip_address: '',
    role: 'appserver',
    haproxy_backend: '',
    haproxy_server_name: '',
    canary_vhost: '',
    sort_order: 0,
    active: true,
});

const load = async () => {
    loading.value = true;

    try {
        data.value = await api.get(endpoint('targets'));
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

const byRole = computed(() => {
    const grouped = {};

    (data.value?.data ?? []).forEach((target) => {
        (grouped[target.role] ??= []).push(target);
    });

    return grouped;
});

const roleLabel = (role) =>
    (data.value?.roles ?? []).find((entry) => entry.value === role)?.label ?? role;

const open = (target = null) => {
    editing.value = target ?? { id: null };
    errors.value = {};

    form.value = target
        ? {
              hostname: target.hostname,
              ip_address: target.ip_address ?? '',
              role: target.role,
              haproxy_backend: target.haproxy_backend ?? '',
              haproxy_server_name: target.haproxy_server_name ?? '',
              canary_vhost: target.canary_vhost ?? '',
              sort_order: target.sort_order,
              active: target.active,
          }
        : {
              hostname: '',
              ip_address: '',
              role: 'appserver',
              haproxy_backend: '',
              haproxy_server_name: '',
              canary_vhost: '',
              sort_order: 0,
              active: true,
          };
};

const submit = async () => {
    busy.value = true;
    errors.value = {};

    const body = {
        ...form.value,
        ip_address: form.value.ip_address || null,
        haproxy_backend: form.value.haproxy_backend || null,
        haproxy_server_name: form.value.haproxy_server_name || null,
        canary_vhost: form.value.canary_vhost || null,
    };

    try {
        const payload = editing.value.id
            ? await api.put(endpoint(`targets/${editing.value.id}`), body)
            : await api.post(endpoint('targets'), body);

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

const deactivate = async (target) => {
    busy.value = true;

    try {
        const payload = await api.delete(endpoint(`targets/${target.id}`));

        flash(payload.message);
        await load();
    } catch (thrown) {
        flashError(thrown);
    } finally {
        busy.value = false;
    }
};

const pool = async (target, action) => {
    busy.value = true;

    try {
        const payload = await api.post(endpoint(`targets/${target.id}/pool`), { action });

        flash(payload.message);
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
            <h1 class="text-lg font-semibold tracking-tight">Deploy targets</h1>
            <button
                type="button"
                class="ml-auto rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700"
                @click="open()"
            >
                Add a target
            </button>
        </header>

        <LoadState :loading="loading" :error="error" @retry="load">
            <div v-if="data" class="space-y-4">
                <p class="text-sm text-slate-500">
                    The staging host is configured, not registered:
                    <code class="font-mono text-xs">{{ data.settings.staging_host }}</code>. Every hostname below
                    must match a Salt minion id exactly — check against <code class="font-mono">salt-key -L</code>.
                </p>

                <CardPanel
                    v-for="(targets, role) in byRole"
                    :key="role"
                    :title="roleLabel(role)"
                    :subtitle="`${targets.length} registered`"
                    flush
                >
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 text-left text-xs tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-5 py-2">Hostname</th>
                                <th class="px-5 py-2">IP address</th>
                                <th class="px-5 py-2">HAProxy</th>
                                <th class="px-5 py-2">Canary vhost</th>
                                <th class="px-5 py-2">State</th>
                                <th class="px-5 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="target in targets" :key="target.id">
                                <td class="px-5 py-2 font-mono text-xs">{{ target.hostname }}</td>
                                <td class="px-5 py-2 font-mono text-xs">
                                    <span v-if="target.ip_address">{{ target.ip_address }}</span>
                                    <span v-else class="text-slate-400">canary uses 127.0.0.1</span>
                                </td>
                                <td class="px-5 py-2 text-xs">
                                    <span class="text-slate-500">backend</span>
                                    {{ target.haproxy_backend ?? data.settings.haproxy_backend }}
                                    <span class="block text-slate-500">as {{ target.haproxy_effective_name }}</span>
                                </td>
                                <td class="px-5 py-2 font-mono text-xs">{{ target.canary_effective_vhost }}</td>
                                <td class="px-5 py-2">
                                    <span :class="target.active ? 'text-emerald-700' : 'text-slate-400'">
                                        {{ target.active ? 'active' : 'inactive' }}
                                    </span>
                                </td>
                                <td class="px-5 py-2 text-right text-sm whitespace-nowrap">
                                    <template v-if="data.can.pool && target.role === 'appserver'">
                                        <button
                                            type="button"
                                            class="text-slate-600 underline disabled:opacity-50"
                                            :disabled="busy"
                                            @click="pool(target, 'depool')"
                                        >
                                            Depool
                                        </button>
                                        <button
                                            type="button"
                                            class="ml-3 text-slate-600 underline disabled:opacity-50"
                                            :disabled="busy"
                                            @click="pool(target, 'repool')"
                                        >
                                            Repool
                                        </button>
                                    </template>
                                    <button type="button" class="ml-3 text-slate-600 underline" @click="open(target)">
                                        Edit
                                    </button>
                                    <button
                                        v-if="target.active"
                                        type="button"
                                        class="ml-3 text-rose-700 underline disabled:opacity-50"
                                        :disabled="busy"
                                        @click="deactivate(target)"
                                    >
                                        Deactivate
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardPanel>

                <p v-if="(data.data ?? []).length === 0" class="text-sm text-slate-500">
                    No targets are registered, so every deployment can only be staging-only.
                </p>
            </div>
        </LoadState>

        <ModalDialog
            v-if="editing"
            :title="editing.id ? `Edit ${form.hostname}` : 'Add a deploy target'"
            @close="editing = null"
        >
            <div class="space-y-4">
                <FormField
                    label="Hostname"
                    required
                    :error="errors.hostname?.[0]"
                    hint="The Salt minion id, exactly."
                >
                    <input
                        v-model="form.hostname"
                        type="text"
                        class="block w-full rounded-md bg-white px-3 py-2 font-mono text-sm ring-1 ring-inset ring-slate-300"
                    />
                </FormField>

                <FormField
                    label="IP address"
                    :error="errors.ip_address?.[0]"
                    hint="Optional; the canary check connects here directly and sends the vhost as a Host header. Without it, the check falls back to 127.0.0.1 and only works if this server's web server listens on loopback."
                >
                    <input
                        v-model="form.ip_address"
                        type="text"
                        placeholder="e.g. 10.0.4.12"
                        class="block w-full rounded-md bg-white px-3 py-2 font-mono text-sm ring-1 ring-inset ring-slate-300"
                    />
                </FormField>

                <FormField label="Role" required :error="errors.role?.[0]">
                    <select
                        v-model="form.role"
                        class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                    >
                        <option v-for="role in data.roles" :key="role.value" :value="role.value">
                            {{ role.label }}
                        </option>
                    </select>
                </FormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="HAProxy backend"
                        :error="errors.haproxy_backend?.[0]"
                        :hint="`Optional; defaults to ${data.settings.haproxy_backend}.`"
                    >
                        <input
                            v-model="form.haproxy_backend"
                            type="text"
                            class="block w-full rounded-md bg-white px-3 py-2 font-mono text-sm ring-1 ring-inset ring-slate-300"
                        />
                    </FormField>

                    <FormField
                        label="HAProxy server name"
                        :error="errors.haproxy_server_name?.[0]"
                        hint="Optional; defaults to the hostname."
                    >
                        <input
                            v-model="form.haproxy_server_name"
                            type="text"
                            class="block w-full rounded-md bg-white px-3 py-2 font-mono text-sm ring-1 ring-inset ring-slate-300"
                        />
                    </FormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Canary vhost"
                        :error="errors.canary_vhost?.[0]"
                        :hint="`Optional; defaults to ${data.settings.canary_vhost}.`"
                    >
                        <input
                            v-model="form.canary_vhost"
                            type="text"
                            class="block w-full rounded-md bg-white px-3 py-2 font-mono text-sm ring-1 ring-inset ring-slate-300"
                        />
                    </FormField>

                    <FormField label="Sort order" :error="errors.sort_order?.[0]" hint="Rollout order, ascending.">
                        <input
                            v-model.number="form.sort_order"
                            type="number"
                            min="0"
                            class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                        />
                    </FormField>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.active" type="checkbox" class="rounded border-slate-300" />
                    Active — included in deployments
                </label>
            </div>

            <template #footer>
                <button type="button" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-slate-300" @click="editing = null">
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50"
                    :disabled="busy || !form.hostname"
                    @click="submit"
                >
                    {{ busy ? 'Saving…' : 'Save' }}
                </button>
            </template>
        </ModalDialog>
    </div>
</template>
