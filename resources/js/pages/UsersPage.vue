<script setup>
import { computed, onMounted, ref } from 'vue';

import { ApiError, api, endpoint } from '../api';
import CardPanel from '../components/CardPanel.vue';
import FormField from '../components/FormField.vue';
import LoadState from '../components/LoadState.vue';
import ModalDialog from '../components/ModalDialog.vue';
import { flash, flashError } from '../store';

/**
 * Accounts, roles and per-repository scoping.
 *
 * There is no self-registration: this portal can push code to every production
 * appserver, so accounts are made by someone holding users.manage.
 */
const data = ref(null);
const loading = ref(true);
const error = ref(null);
const busy = ref(false);
const errors = ref({});

const showCreate = ref(false);
const form = ref({ name: '', email: '', password: '', roles: [] });

const scopeForm = ref({ repository_id: '', user_id: '', role_id: '' });

const load = async () => {
    loading.value = true;

    try {
        data.value = await api.get(endpoint('users'));
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

const users = computed(() => data.value?.users ?? []);

const create = async () => {
    busy.value = true;
    errors.value = {};

    try {
        const payload = await api.post(endpoint('users'), form.value);

        flash(payload.message);
        showCreate.value = false;
        form.value = { name: '', email: '', password: '', roles: [] };
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

const toggleRole = async (user, roleId) => {
    const roles = user.role_ids.includes(roleId)
        ? user.role_ids.filter((id) => id !== roleId)
        : [...user.role_ids, roleId];

    busy.value = true;

    try {
        const payload = await api.put(endpoint(`users/${user.id}`), { roles });

        flash(payload.message);
        await load();
    } catch (thrown) {
        flashError(thrown);
    } finally {
        busy.value = false;
    }
};

const addScope = async () => {
    busy.value = true;

    try {
        const payload = await api.post(endpoint('users/repository-scope'), {
            repository_id: Number(scopeForm.value.repository_id) || null,
            user_id: Number(scopeForm.value.user_id) || null,
            role_id: Number(scopeForm.value.role_id) || null,
        });

        flash(payload.message);
        scopeForm.value = { repository_id: '', user_id: '', role_id: '' };
        await load();
    } catch (thrown) {
        flashError(thrown);
    } finally {
        busy.value = false;
    }
};

const removeScope = async (scope) => {
    busy.value = true;

    try {
        const payload = await api.delete(endpoint(`users/repository-scope/${scope.id}`));

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
            <h1 class="text-lg font-semibold tracking-tight">Users and roles</h1>
            <button
                type="button"
                class="ml-auto rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700"
                @click="showCreate = true"
            >
                Create an account
            </button>
        </header>

        <LoadState :loading="loading" :error="error" @retry="load">
            <div v-if="data" class="space-y-6">
                <CardPanel title="Accounts" subtitle="Tick a role to grant it. TOTP is enforced for anything beyond read-only." flush>
                    <table class="w-full text-sm">
                        <thead class="border-b border-slate-200 text-left text-xs tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th class="px-5 py-2">Account</th>
                                <th class="px-5 py-2">2FA</th>
                                <th class="px-5 py-2">Roles</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="user in users" :key="user.id" class="align-top">
                                <td class="px-5 py-2">
                                    <p class="font-medium">{{ user.name }}</p>
                                    <p class="text-xs text-slate-500">{{ user.email }}</p>
                                </td>
                                <td class="px-5 py-2">
                                    <span v-if="user.two_factor_enabled" class="text-xs text-emerald-700">enrolled</span>
                                    <span v-else-if="user.two_factor_required" class="text-xs text-rose-700">
                                        required, not enrolled
                                    </span>
                                    <span v-else class="text-xs text-slate-400">not required</span>
                                </td>
                                <td class="px-5 py-2">
                                    <div class="flex flex-wrap gap-2">
                                        <label
                                            v-for="role in data.roles"
                                            :key="role.id"
                                            class="flex items-center gap-1.5 rounded border border-slate-200 px-2 py-0.5 text-xs"
                                        >
                                            <input
                                                type="checkbox"
                                                class="rounded border-slate-300"
                                                :checked="user.role_ids.includes(role.id)"
                                                :disabled="busy"
                                                @change="toggleRole(user, role.id)"
                                            />
                                            {{ role.name }}
                                        </label>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardPanel>

                <CardPanel title="Roles" subtitle="What each role grants">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="role in data.roles" :key="role.id">
                            <p class="text-sm font-medium">{{ role.name }}</p>
                            <p v-if="role.description" class="text-xs text-slate-500">{{ role.description }}</p>
                            <ul class="mt-1 space-y-0.5">
                                <li v-for="permission in role.permissions" :key="permission" class="font-mono text-xs text-slate-600">
                                    {{ permission }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </CardPanel>

                <CardPanel
                    title="Per-repository scoping"
                    subtitle="A repository with no rows here is governed purely by its deploy.<type> permission. Adding the first row narrows it to the listed users and roles."
                >
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li
                            v-for="scope in data.repository_permissions"
                            :key="scope.id"
                            class="flex flex-wrap items-center gap-2 py-2"
                        >
                            <span class="font-medium">{{ scope.repository_name }}</span>
                            <span class="text-slate-500">
                                → {{ scope.user_name ?? scope.role_name ?? 'unknown' }}
                                <span class="text-xs">({{ scope.user_name ? 'user' : 'role' }})</span>
                            </span>
                            <button
                                type="button"
                                class="ml-auto text-xs text-rose-700 underline disabled:opacity-50"
                                :disabled="busy"
                                @click="removeScope(scope)"
                            >
                                Remove
                            </button>
                        </li>
                        <li v-if="data.repository_permissions.length === 0" class="py-2 text-slate-500">
                            No repository is scoped; the coarse permissions apply everywhere.
                        </li>
                    </ul>

                    <div class="mt-4 grid gap-3 sm:grid-cols-4">
                        <select
                            v-model="scopeForm.repository_id"
                            class="rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                        >
                            <option value="">Repository…</option>
                            <option v-for="repository in data.repositories" :key="repository.id" :value="repository.id">
                                {{ repository.name }} ({{ repository.type }})
                            </option>
                        </select>
                        <select
                            v-model="scopeForm.user_id"
                            class="rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                        >
                            <option value="">User…</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">{{ user.email }}</option>
                        </select>
                        <select
                            v-model="scopeForm.role_id"
                            class="rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                        >
                            <option value="">…or role</option>
                            <option v-for="role in data.roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                        </select>
                        <button
                            type="button"
                            class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-40"
                            :disabled="busy || !scopeForm.repository_id"
                            @click="addScope"
                        >
                            Add scope
                        </button>
                    </div>
                </CardPanel>
            </div>
        </LoadState>

        <ModalDialog
            v-if="showCreate"
            title="Create an account"
            subtitle="They will have to enrol TOTP before they can deploy."
            @close="showCreate = false"
        >
            <div class="space-y-4">
                <FormField label="Name" required :error="errors.name?.[0]">
                    <input
                        v-model="form.name"
                        type="text"
                        class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                    />
                </FormField>

                <FormField label="Email" required :error="errors.email?.[0]">
                    <input
                        v-model="form.email"
                        type="email"
                        class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                    />
                </FormField>

                <FormField label="Password" required :error="errors.password?.[0]" hint="At least 12 characters.">
                    <input
                        v-model="form.password"
                        type="password"
                        class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                    />
                </FormField>

                <div>
                    <p class="text-sm font-medium text-slate-700">Roles</p>
                    <div class="mt-1 flex flex-wrap gap-2">
                        <label
                            v-for="role in data.roles"
                            :key="role.id"
                            class="flex items-center gap-1.5 rounded border border-slate-200 px-2 py-1 text-sm"
                        >
                            <input v-model="form.roles" type="checkbox" class="rounded border-slate-300" :value="role.id" />
                            {{ role.name }}
                        </label>
                    </div>
                </div>
            </div>

            <template #footer>
                <button type="button" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-slate-300" @click="showCreate = false">
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50"
                    :disabled="busy"
                    @click="create"
                >
                    {{ busy ? 'Creating…' : 'Create' }}
                </button>
            </template>
        </ModalDialog>
    </div>
</template>
