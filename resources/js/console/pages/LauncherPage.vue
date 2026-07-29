<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';

import AppIcon from '../../components/AppIcon.vue';
import { pluralise } from '../../format';
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
    <div class="space-y-10">
        <header>
            <h1 class="text-2xl font-semibold text-balance">{{ consoleName }}</h1>
            <p class="mt-2 max-w-prose text-sm text-pretty text-fg-muted">
                Signed in as {{ session.user?.name }}.
                <template v-if="available.length > 0">
                    {{ pluralise(available.length, 'app') }}
                    {{ available.length === 1 ? 'is' : 'are' }} available to you.
                </template>
            </p>
        </header>

        <section v-if="available.length > 0" aria-label="Your apps">
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <li v-for="entry in available" :key="entry.id" class="flex">
                    <RouterLink
                        :to="entry.path"
                        class="panel group flex grow flex-col p-5 motion-safe:transition-[box-shadow,border-color,translate] motion-safe:duration-200 hover:border-accent-line hover:shadow-hover motion-safe:hover:-translate-y-0.5"
                    >
                        <span
                            class="inline-flex size-10 items-center justify-center rounded-xl bg-accent text-sm font-bold text-accent-fg shadow-panel"
                            aria-hidden="true"
                            >{{ entry.icon }}</span
                        >

                        <p class="mt-4 font-semibold group-hover:text-accent-text">{{ entry.name }}</p>
                        <p class="mt-1 grow text-sm text-pretty text-fg-muted">{{ entry.summary }}</p>

                        <p class="numeric mt-4 border-t border-line pt-3 text-xs text-fg-subtle">
                            <span v-if="readOnly(entry)">Read-only access</span>
                            <span v-else>{{ entry.granted.length }} of {{ entry.permission_count }} permissions</span>
                        </p>
                    </RouterLink>
                </li>
            </ul>
        </section>

        <!-- An account with no grants anywhere still signs in successfully; saying
             so plainly beats an empty page that looks broken. -->
        <section v-else class="panel px-6 py-12 text-center">
            <p class="text-sm font-medium">No apps are available to your account yet</p>
            <p class="mx-auto mt-1.5 max-w-md text-sm text-pretty text-fg-subtle">
                Access is granted per app. Ask whoever administers this console to add you to a role that includes
                the app you need.
            </p>
        </section>

        <section v-if="locked.length > 0">
            <h2 class="label-caps">Not available to you</h2>
            <p class="mt-1.5 max-w-prose text-sm text-pretty text-fg-subtle">
                These are installed but your account has no grant in them. The permission to ask for is below each
                one.
            </p>
            <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <li
                    v-for="entry in locked"
                    :key="entry.id"
                    class="flex flex-col rounded-xl border border-dashed border-line-strong p-5"
                >
                    <span
                        class="inline-flex size-10 items-center justify-center rounded-xl bg-sunken text-fg-subtle"
                        aria-hidden="true"
                    >
                        <AppIcon name="lock" class="size-4" />
                    </span>
                    <p class="mt-4 font-semibold text-fg-muted">{{ entry.name }}</p>
                    <p class="mt-1 grow text-sm text-pretty text-fg-subtle">{{ entry.summary }}</p>
                    <p class="mt-4 border-t border-line pt-3">
                        <code class="font-mono text-xs break-all text-fg-subtle">apps.{{ entry.id }}.access</code>
                    </p>
                </li>
            </ul>
        </section>

        <!-- Not an app: this is how the apps are handed out, so it is never
             behind an app grant. -->
        <section v-if="can('manage_users')">
            <h2 class="label-caps">Console administration</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <RouterLink
                    to="/access"
                    class="panel group flex flex-col p-5 motion-safe:transition-[box-shadow,border-color,translate] motion-safe:duration-200 hover:border-accent-line hover:shadow-hover motion-safe:hover:-translate-y-0.5"
                >
                    <span
                        class="inline-flex size-10 items-center justify-center rounded-xl border border-line-strong bg-sunken text-fg-muted"
                        aria-hidden="true"
                    >
                        <AppIcon name="users" class="size-4" />
                    </span>
                    <p class="mt-4 font-semibold group-hover:text-accent-text">Users and access</p>
                    <p class="mt-1 text-sm text-pretty text-fg-muted">
                        Accounts, roles, and which of each app's permissions a role grants.
                    </p>
                </RouterLink>
            </div>
        </section>
    </div>
</template>
