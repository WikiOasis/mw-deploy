<script setup>
import { computed, onMounted, ref } from 'vue';

import { ApiError, api, endpoint } from '../../api';
import AppButton from '../../components/AppButton.vue';
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
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Users and access</h1>
                <p class="mt-1.5 max-w-prose text-sm text-pretty text-fg-muted">
                    Accounts hold roles, roles hold permissions, and every permission belongs to one app. This is
                    how the apps on someone's launcher are decided.
                </p>
            </div>
            <AppButton variant="primary" icon="plus" @click="showCreate = true">Create an account</AppButton>
        </header>

        <LoadState :loading="loading" :error="error" @retry="load">
            <div v-if="data" class="space-y-6">
                <CardPanel
                    title="Accounts"
                    subtitle="Tick a role to grant it. TOTP is enforced for anything beyond read-only."
                    flush
                >
                    <table class="w-full text-sm">
                        <thead class="label-caps border-b border-line text-start">
                            <tr>
                                <th class="px-5 py-2">Account</th>
                                <th class="px-5 py-2">2FA</th>
                                <th class="px-5 py-2">Roles</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="user in users" :key="user.id" class="align-top">
                                <td class="px-5 py-2">
                                    <p class="font-medium">{{ user.name }}</p>
                                    <p class="text-xs text-fg-subtle">{{ user.email }}</p>
                                    <!-- An account whose roles come from an IdP
                                         group is one whose roles come back after
                                         being edited here. Better seen than
                                         discovered. -->
                                    <p v-if="user.single_sign_on" class="mt-0.5 text-2xs text-fg-faint">
                                        signs in with single sign-on{{ user.password_set ? ' and a password' : '' }}
                                    </p>
                                </td>
                                <td class="px-5 py-2">
                                    <span v-if="user.two_factor_enabled" class="text-xs text-success-text">enrolled</span>
                                    <span v-else-if="user.two_factor_required" class="text-xs text-danger-text">
                                        required, not enrolled
                                    </span>
                                    <span v-else class="text-xs text-fg-subtle">not required</span>
                                </td>
                                <td class="px-5 py-2">
                                    <div class="flex flex-wrap gap-2">
                                        <label
                                            v-for="role in roles"
                                            :key="role.id"
                                            class="flex items-center gap-1.5 rounded border border-line px-2 py-0.5 text-xs"
                                        >
                                            <input
                                                type="checkbox"
                                                class="size-4 rounded border-line-strong"
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
                            class="link-quiet"
                            @click="openRole(null)"
                        >
                            New role
                        </button>
                    </template>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-for="role in roles" :key="role.id" class="rounded-md border border-line p-3">
                            <div class="flex items-start gap-2">
                                <div>
                                    <p class="text-sm font-medium">{{ role.name }}</p>
                                    <p v-if="role.description" class="text-xs text-fg-subtle">{{ role.description }}</p>
                                </div>
                                <button
                                    v-if="can('manage_roles')"
                                    type="button"
                                    class="ms-auto inline-flex min-h-8 items-center rounded-md px-2 text-xs text-fg-muted hover:bg-sunken hover:text-fg"
                                    @click="openRole(role)"
                                >
                                    Edit
                                </button>
                            </div>

                            <div class="mt-2 flex flex-wrap gap-1">
                                <span
                                    v-for="app in role.apps"
                                    :key="app"
                                    class="rounded bg-sunken px-1.5 py-0.5 text-xs font-medium text-fg"
                                >
                                    {{ groupLabel(app) }}
                                </span>
                                <span v-if="role.apps.length === 0" class="text-xs text-fg-subtle">opens no apps</span>
                            </div>

                            <ul class="mt-2 space-y-0.5">
                                <li
                                    v-for="permission in role.permissions"
                                    :key="permission"
                                    class="font-mono text-xs text-fg-muted"
                                >
                                    {{ permission }}
                                </li>
                                <li v-if="role.permissions.length === 0" class="text-xs text-fg-subtle">
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
                <FormField label="Name" required :error="errors.name?.[0]" v-slot="field">
                    <input v-bind="field"
                        v-model="form.name"
                        type="text"
                        class="input-control block w-full"
                    />
                </FormField>

                <FormField label="Email" required :error="errors.email?.[0]" v-slot="field">
                    <input v-bind="field"
                        v-model="form.email"
                        type="email"
                        class="input-control block w-full"
                    />
                </FormField>

                <FormField label="Password" required :error="errors.password?.[0]" hint="At least 12 characters." v-slot="field">
                    <input v-bind="field"
                        v-model="form.password"
                        type="password"
                        class="input-control block w-full"
                    />
                </FormField>

                <div>
                    <p class="text-sm font-medium text-fg">Roles</p>
                    <div class="mt-1 flex flex-wrap gap-2">
                        <label
                            v-for="role in roles"
                            :key="role.id"
                            class="flex items-center gap-1.5 rounded border border-line px-2 py-1 text-sm"
                        >
                            <input v-model="form.roles" type="checkbox" class="size-4 rounded border-line-strong" :value="role.id" />
                            {{ role.name }}
                        </label>
                    </div>
                </div>
            </div>

            <template #footer>
                <button type="button" class="btn btn-secondary" @click="showCreate = false">
                    Cancel
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
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
                 v-slot="field">
                    <input v-bind="field"
                        v-model="roleForm.name"
                        type="text"
                        class="input-control block w-full"
                    />
                </FormField>

                <FormField label="Description" :error="errors.description?.[0]" v-slot="field">
                    <input v-bind="field"
                        v-model="roleForm.description"
                        type="text"
                        class="input-control block w-full"
                    />
                </FormField>

                <p v-if="errors.permissions" class="text-xs text-danger-text">{{ errors.permissions[0] }}</p>

                <div v-for="group in groups" :key="group.key" class="rounded-md border border-line p-3">
                    <p class="text-sm font-medium">{{ group.label }}</p>
                    <p class="text-xs text-fg-subtle">{{ group.summary }}</p>

                    <div class="mt-2 space-y-1">
                        <label
                            v-for="permission in group.permissions"
                            :key="permission.name"
                            class="flex items-start gap-2 text-sm"
                        >
                            <input
                                v-model="roleForm.permissions"
                                type="checkbox"
                                class="mt-1 size-4 rounded border-line-strong"
                                :value="permission.name"
                            />
                            <span>
                                <span class="font-mono text-xs">{{ permission.name }}</span>
                                <span
                                    v-if="permission.grants_access"
                                    class="ms-1 rounded-sm border border-info-line bg-info-surface px-1.5 py-0.5 text-2xs font-medium text-info-text"
                                    >opens the app</span
                                >
                                <span class="block text-xs text-fg-subtle">{{ permission.description }}</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <template #footer>
                <button type="button" class="btn btn-secondary" @click="showRole = false">
                    Cancel
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="busy"
                    @click="saveRole"
                >
                    {{ busy ? 'Saving…' : 'Save' }}
                </button>
            </template>
        </ModalDialog>
    </div>
</template>
