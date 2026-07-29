<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';

import { apps, can, consoleName, session } from '../../store';

/**
 * The launcher: what this account can open.
 *
 * The first screen after signing in, and the reason the console exists as
 * something separate from its apps. The list comes from the server — every app
 * this install has switched on, each flagged with whether the account may open it
 * — so a locked tile is shown rather than hidden. Someone who cannot see an app
 * at all has no way to know what to ask for, and that becomes a support ticket
 * beginning "I can't see anything".
 */
const available = computed(() => apps.value.filter((entry) => entry.accessible));

const locked = computed(() => apps.value.filter((entry) => !entry.accessible));

/**
 * Read-only in an app: the account holds the app's access permission and nothing
 * else from it.
 */
const readOnly = (entry) =>
    entry.granted.every((name) => name.startsWith('apps.') && name.endsWith('.access'));
</script>

<template>
    <div class="space-y-8">
        <header>
            <h1 class="text-xl font-semibold tracking-tight">{{ consoleName }}</h1>
            <p class="mt-1 text-sm text-slate-600">
                Signed in as {{ session.user?.name }}.
                <template v-if="available.length > 0">
                    {{ available.length === 1 ? 'One app is' : `${available.length} apps are` }} available to you.
                </template>
            </p>
        </header>

        <section v-if="available.length > 0">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <RouterLink
                    v-for="entry in available"
                    :key="entry.id"
                    :to="entry.path"
                    class="group flex flex-col rounded-lg border border-slate-200 bg-white p-5 shadow-sm transition hover:border-slate-400 hover:shadow"
                >
                    <span
                        class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-900 text-sm font-bold tracking-tight text-white"
                        >{{ entry.icon }}</span
                    >

                    <p class="mt-3 font-semibold tracking-tight group-hover:underline">{{ entry.name }}</p>
                    <p class="mt-1 grow text-sm text-slate-600">{{ entry.summary }}</p>

                    <p class="mt-3 text-xs text-slate-500">
                        <span v-if="readOnly(entry)">Read-only access</span>
                        <span v-else>{{ entry.granted.length }} of {{ entry.permission_count }} permissions</span>
                    </p>
                </RouterLink>
            </div>
        </section>

        <!-- An account with no grants anywhere still signs in successfully; saying
             so plainly beats an empty page that looks broken. -->
        <section v-else class="rounded-lg border border-slate-200 bg-white p-6 text-sm">
            <p class="font-medium">No apps are available to your account yet.</p>
            <p class="mt-1 text-slate-600">
                Access is granted per app. Ask someone who administers this console to add you to a role that
                includes the app you need.
            </p>
        </section>

        <section v-if="locked.length > 0">
            <h2 class="text-xs font-medium tracking-wide text-slate-500 uppercase">Not available to you</h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="entry in locked"
                    :key="entry.id"
                    class="flex flex-col rounded-lg border border-dashed border-slate-200 p-5"
                >
                    <span
                        class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-200 text-sm font-bold tracking-tight text-slate-500"
                        >{{ entry.icon }}</span
                    >
                    <p class="mt-3 font-semibold tracking-tight text-slate-500">{{ entry.name }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ entry.summary }}</p>
                    <p class="mt-3 font-mono text-xs text-slate-400">apps.{{ entry.id }}.access</p>
                </div>
            </div>
        </section>

        <!-- Not an app: this is how the apps are handed out, so it is never
             behind an app grant. -->
        <section v-if="can('manage_users')">
            <h2 class="text-xs font-medium tracking-wide text-slate-500 uppercase">Console administration</h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <RouterLink
                    to="/access"
                    class="group flex flex-col rounded-lg border border-slate-200 bg-white p-5 shadow-sm transition hover:border-slate-400 hover:shadow"
                >
                    <span
                        class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-white text-sm font-bold tracking-tight text-slate-700 ring-1 ring-slate-300 ring-inset"
                        >ac</span
                    >
                    <p class="mt-3 font-semibold tracking-tight group-hover:underline">Users and access</p>
                    <p class="mt-1 text-sm text-slate-600">
                        Accounts, roles, and which of each app's permissions a role grants.
                    </p>
                </RouterLink>
            </div>
        </section>
    </div>
</template>
