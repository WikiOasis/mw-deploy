<script setup>
import { computed, onMounted, ref } from 'vue';

import { ApiError, api, endpoint } from '../../../api';
import CardPanel from '../../../components/CardPanel.vue';
import LoadState from '../../../components/LoadState.vue';
import { flash, flashError } from '../../../store';

/**
 * Per-repository scoping: this app's own narrowing of who may act on which
 * repository, on top of the coarse deploy.<type> permissions the console hands
 * out.
 *
 * It lives inside the deployments app rather than on the console's access screen
 * because it is about repositories — the console deals in accounts, roles and
 * which apps they reach. Editing it still wants users.manage.
 */
const data = ref(null);
const loading = ref(true);
const error = ref(null);
const busy = ref(false);

const form = ref({ repository_id: '', user_id: '', role_id: '' });

const load = async () => {
    loading.value = true;

    try {
        data.value = await api.get(endpoint('repository-scope'));
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

const scopes = computed(() => data.value?.scopes ?? []);

const add = async () => {
    busy.value = true;

    try {
        const payload = await api.post(endpoint('repository-scope'), {
            repository_id: Number(form.value.repository_id) || null,
            user_id: Number(form.value.user_id) || null,
            role_id: Number(form.value.role_id) || null,
        });

        flash(payload.message);
        form.value = { repository_id: '', user_id: '', role_id: '' };
        await load();
    } catch (thrown) {
        flashError(thrown);
    } finally {
        busy.value = false;
    }
};

const remove = async (scope) => {
    busy.value = true;

    try {
        const payload = await api.delete(endpoint(`repository-scope/${scope.id}`));

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
        <header>
            <h1 class="text-xl font-semibold">Repository access</h1>
            <p class="mt-1.5 max-w-prose text-sm text-pretty text-fg-muted">
                Who may act on which repository, within this app. A repository with no rows is governed only by its
                deploy permission.
            </p>
        </header>

        <LoadState :loading="loading" :error="error" @retry="load">
            <CardPanel
                v-if="data"
                title="Per-repository scoping"
                subtitle="A repository with no rows here is governed purely by its deploy.<type> permission. Adding the first row narrows it to the listed users and roles."
            >
                <ul class="divide-y divide-line text-sm">
                    <li v-for="scope in scopes" :key="scope.id" class="flex flex-wrap items-center gap-2 py-2">
                        <span class="font-medium">{{ scope.repository_name }}</span>
                        <span class="text-fg-subtle">
                            → {{ scope.user_name ?? scope.role_name ?? 'unknown' }}
                            <span class="text-xs">({{ scope.user_name ? 'user' : 'role' }})</span>
                        </span>
                        <button
                            type="button"
                            class="ms-auto inline-flex min-h-8 items-center rounded-md px-2 text-xs text-danger-text hover:bg-danger-surface disabled:opacity-50"
                            :disabled="busy"
                            @click="remove(scope)"
                        >
                            Remove
                        </button>
                    </li>
                    <li v-if="scopes.length === 0" class="py-2 text-fg-subtle">
                        No repository is scoped; the coarse permissions apply everywhere.
                    </li>
                </ul>

                <div class="mt-4 grid gap-3 sm:grid-cols-4">
                    <select
                        v-model="form.repository_id"
                        class="input-control"
                    >
                        <option value="">Repository…</option>
                        <option v-for="repository in data.repositories" :key="repository.id" :value="repository.id">
                            {{ repository.name }} ({{ repository.type }})
                        </option>
                    </select>
                    <select
                        v-model="form.user_id"
                        class="input-control"
                    >
                        <option value="">User…</option>
                        <option v-for="user in data.users" :key="user.id" :value="user.id">{{ user.email }}</option>
                    </select>
                    <select
                        v-model="form.role_id"
                        class="input-control"
                    >
                        <option value="">…or role</option>
                        <option v-for="role in data.roles" :key="role.id" :value="role.id">{{ role.name }}</option>
                    </select>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="busy || !form.repository_id"
                        @click="add"
                    >
                        Add scope
                    </button>
                </div>
            </CardPanel>
        </LoadState>
    </div>
</template>
