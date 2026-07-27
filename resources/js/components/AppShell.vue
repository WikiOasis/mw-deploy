<script setup>
import { computed } from 'vue';
import { RouterLink, RouterView } from 'vue-router';

import { can, dismissFlash, registryIsEmpty, session } from '../store';

/**
 * Nav, flash messages and the routed page.
 *
 * The nav is permission-driven from the bootstrap payload, so a reader never sees
 * a Targets link that would 403, and a fresh install gets pointed at the import
 * screen rather than at five empty lists.
 */
const links = computed(() =>
    [
        { to: '/', label: 'Dashboard', show: true },
        { to: '/deployments', label: 'History', show: true },
        { to: '/versions', label: 'Versions', show: true },
        { to: '/repositories', label: 'Repositories', show: true },
        { to: '/import', label: 'Import', show: can('manage_repositories') },
        { to: '/patches', label: 'Patches', show: true },
        { to: '/targets', label: 'Targets', show: can('manage_targets') },
        { to: '/users', label: 'Users', show: can('manage_users') },
    ].filter((link) => link.show),
);

const flashClasses = (kind) =>
    ({
        success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
        error: 'border-rose-200 bg-rose-50 text-rose-900',
        info: 'border-sky-200 bg-sky-50 text-sky-900',
    })[kind] ?? 'border-slate-200 bg-slate-50 text-slate-900';
</script>

<template>
    <div class="min-h-full">
        <nav class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 sm:px-6">
                <RouterLink to="/" class="flex items-center gap-2 font-semibold tracking-tight">
                    <span
                        class="inline-flex h-6 w-6 items-center justify-center rounded bg-slate-900 text-xs font-bold text-white"
                        >mw</span
                    >
                    Deploy Portal
                </RouterLink>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                    <RouterLink
                        v-for="link in links"
                        :key="link.to"
                        :to="link.to"
                        class="border-b-2 border-transparent pb-0.5 text-slate-600 hover:text-slate-900"
                        active-class="border-slate-900! font-medium text-slate-900!"
                    >
                        {{ link.label }}
                    </RouterLink>
                </div>

                <div class="ml-auto flex items-center gap-3 text-sm">
                    <RouterLink
                        v-if="can('deploy')"
                        to="/deployments/new"
                        class="rounded-md bg-slate-900 px-3 py-1.5 font-medium text-white hover:bg-slate-700"
                    >
                        New deployment
                    </RouterLink>

                    <a href="/two-factor/setup" class="text-slate-600 hover:text-slate-900">
                        {{ session.user?.name }}
                        <!-- Only for accounts that actually need it: a read-only
                             account is not being nagged about a requirement that
                             does not apply to it. -->
                        <span
                            v-if="!session.user?.two_factor_enabled && session.user?.two_factor_required"
                            class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900"
                            >no 2FA</span
                        >
                    </a>

                    <!-- Sign-out is a real form post: Fortify owns the session, and
                         logging out is not something to route client-side. -->
                    <form method="POST" action="/logout">
                        <input type="hidden" name="_token" :value="$csrf" />
                        <button type="submit" class="text-slate-500 hover:text-slate-900">Sign out</button>
                    </form>
                </div>
            </div>
        </nav>

        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
            <div
                v-if="registryIsEmpty && can('manage_repositories')"
                class="mb-6 rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900"
            >
                <p class="font-medium">Nothing is registered yet.</p>
                <p class="mt-1 text-xs">
                    If this farm already has MediaWiki on disk, the portal can read the tree and fill the
                    registry in from it — every version, extension, skin and their current refs.
                    <RouterLink to="/import" class="font-medium underline">Scan the tree</RouterLink>.
                </p>
            </div>

            <div
                v-if="session.user && !session.user.two_factor_enabled && session.user.two_factor_required"
                class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            >
                Your account can change production, so TOTP is required.
                <a href="/two-factor/setup" class="font-medium underline">Enrol now</a>.
            </div>

            <TransitionGroup name="flash" tag="div" class="mb-6 space-y-2">
                <div
                    v-for="entry in session.flashes"
                    :key="entry.id"
                    class="flex items-start justify-between gap-4 rounded-md border px-4 py-3 text-sm"
                    :class="flashClasses(entry.kind)"
                >
                    <span>{{ entry.message }}</span>
                    <button type="button" class="text-xs opacity-60 hover:opacity-100" @click="dismissFlash(entry.id)">
                        Dismiss
                    </button>
                </div>
            </TransitionGroup>

            <RouterView />
        </main>
    </div>
</template>

<style scoped>
.flash-enter-active,
.flash-leave-active {
    transition: opacity 150ms ease;
}

.flash-enter-from,
.flash-leave-to {
    opacity: 0;
}
</style>
