<script setup>
import { computed, onMounted, ref } from 'vue';

import { ApiError, api, endpoint } from '../../api';
import CardPanel from '../../components/CardPanel.vue';
import FormField from '../../components/FormField.vue';
import LoadState from '../../components/LoadState.vue';
import ModalDialog from '../../components/ModalDialog.vue';
import { can, flash, flashError } from '../../store';

/**
 * Central user and access management.
 *
 * The one screen that is deliberately not an app, because it is how the apps are
 * handed out: accounts hold roles, roles hold permissions, and every permission
 * belongs to exactly one app. Ticking an app's access permission is what puts its
 * tile on someone's launcher.
 *
 * There is no self-registration — this console can push code to every production
 * appserver, so accounts are made by someone holding users.manage. Redefining
 * what a role grants is the narrower roles.manage.
 */
const data = ref(null);
const loading = ref(true);
const error = ref(null);
const busy = ref(false);
const errors = ref({});

const showCreate = ref(false);
const form = ref({ name: '', email: '', password: '', roles: [] });

const showRole = ref(false);
const roleForm = ref({ name: '', description: '', permissions: [] });

/** The role being edited, or null while a new one is being created. */
const editing = ref(null);

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
const roles = computed(() => data.value?.roles ?? []);

/** One section per app, plus the console's own, straight from the registry. */
const groups = computed(() => data.value?.permission_groups ?? []);

const groupLabel = (key) => groups.value.find((group) => group.key === key)?.label ?? key;

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
    const assigned = user.role_ids.includes(roleId)
        ? user.role_ids.filter((id) => id !== roleId)
        : [...user.role_ids, roleId];

    busy.value = true;

    try {
        const payload = await api.put(endpoint(`users/${user.id}`), { roles: assigned });

        flash(payload.message);
        await load();
    } catch (thrown) {
        flashError(thrown);
    } finally {
        busy.value = false;
    }
};

const openRole = (role) => {
    editing.value = role ?? null;
    roleForm.value = {
        name: role?.name ?? '',
        description: role?.description ?? '',
        permissions: [...(role?.permissions ?? [])],
    };
    errors.value = {};
    showRole.value = true;
};

const saveRole = async () => {
    busy.value = true;
    errors.value = {};

    try {
        const payload = editing.value
            ? await api.put(endpoint(`roles/${editing.value.id}`), {
                  description: roleForm.value.description,
                  permissions: roleForm.value.permissions,
              })
            : await api.post(endpoint('roles'), roleForm.value);

        flash(payload.message);
        showRole.value = false;
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
</script>

<template>
    <div class="space-y-4">
        <header class="flex flex-wrap items-center gap-3">
            <div>
                <h1 class="text-lg font-semibold tracking-tight">Users and access</h1>
                <p class="text-sm text-slate-600">
                    Accounts hold roles, roles hold permissions, and every permission belongs to one app.
                </p>
            </div>
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
                <CardPanel
                    title="Accounts"
                    subtitle="Tick a role to grant it. TOTP is enforced for anything beyond read-only."
                    flush
                >
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
                                            v-for="role in roles"
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

                <CardPanel
                    title="Roles"
                    subtitle="What each role grants, and which apps it opens. A role holding an app's access permission puts that app on its members' launcher."
                >
                    <template #actions>
                        <button
                            v-if="can('manage_roles')"
                            type="button"
                            class="text-slate-600 underline hover:text-slate-900"
                            @click="openRole(null)"
                        >
                            New role
                        </button>
                    </template>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="role in roles" :key="role.id" class="rounded-md border border-slate-200 p-3">
                            <div class="flex items-start gap-2">
                                <div>
                                    <p class="text-sm font-medium">{{ role.name }}</p>
                                    <p v-if="role.description" class="text-xs text-slate-500">{{ role.description }}</p>
                                </div>
                                <button
                                    v-if="can('manage_roles')"
                                    type="button"
                                    class="ml-auto text-xs text-slate-600 underline hover:text-slate-900"
                                    @click="openRole(role)"
                                >
                                    Edit
                                </button>
                            </div>

                            <div class="mt-2 flex flex-wrap gap-1">
                                <span
                                    v-for="app in role.apps"
                                    :key="app"
                                    class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-700"
                                >
                                    {{ groupLabel(app) }}
                                </span>
                                <span v-if="role.apps.length === 0" class="text-xs text-slate-400">opens no apps</span>
                            </div>

                            <ul class="mt-2 space-y-0.5">
                                <li
                                    v-for="permission in role.permissions"
                                    :key="permission"
                                    class="font-mono text-xs text-slate-600"
                                >
                                    {{ permission }}
                                </li>
                                <li v-if="role.permissions.length === 0" class="text-xs text-slate-400">
                                    grants nothing
                                </li>
                            </ul>
                        </div>
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
                            v-for="role in roles"
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

        <!-- Granting an app is ticking a box in that app's section. The sections
             come from the server's app registry, so a newly installed app appears
             here without this screen knowing anything about it. -->
        <ModalDialog
            v-if="showRole"
            :title="editing ? `Role: ${editing.name}` : 'New role'"
            subtitle="Permissions are grouped by the app they belong to."
            @close="showRole = false"
        >
            <div class="space-y-4">
                <FormField
                    v-if="!editing"
                    label="Name"
                    required
                    :error="errors.name?.[0]"
                    hint="Lowercase, digits and dashes — roles are named in scripts and logs."
                >
                    <input
                        v-model="roleForm.name"
                        type="text"
                        class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                    />
                </FormField>

                <FormField label="Description" :error="errors.description?.[0]">
                    <input
                        v-model="roleForm.description"
                        type="text"
                        class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                    />
                </FormField>

                <p v-if="errors.permissions" class="text-xs text-rose-700">{{ errors.permissions[0] }}</p>

                <div v-for="group in groups" :key="group.key" class="rounded-md border border-slate-200 p-3">
                    <p class="text-sm font-medium">{{ group.label }}</p>
                    <p class="text-xs text-slate-500">{{ group.summary }}</p>

                    <div class="mt-2 space-y-1">
                        <label
                            v-for="permission in group.permissions"
                            :key="permission.name"
                            class="flex items-start gap-2 text-sm"
                        >
                            <input
                                v-model="roleForm.permissions"
                                type="checkbox"
                                class="mt-1 rounded border-slate-300"
                                :value="permission.name"
                            />
                            <span>
                                <span class="font-mono text-xs">{{ permission.name }}</span>
                                <span
                                    v-if="permission.grants_access"
                                    class="ml-1 rounded bg-sky-100 px-1.5 py-0.5 text-xs font-medium text-sky-900"
                                    >opens the app</span
                                >
                                <span class="block text-xs text-slate-500">{{ permission.description }}</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <template #footer>
                <button type="button" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-slate-300" @click="showRole = false">
                    Cancel
                </button>
                <button
                    type="button"
                    class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50"
                    :disabled="busy"
                    @click="saveRole"
                >
                    {{ busy ? 'Saving…' : 'Save' }}
                </button>
            </template>
        </ModalDialog>
    </div>
</template>
