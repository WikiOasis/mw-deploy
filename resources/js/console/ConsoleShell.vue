<script setup>
import { computed, defineAsyncComponent } from 'vue';
import { RouterLink, RouterView, useRoute } from 'vue-router';

import { manifestFor } from '../apps';
import { appById, can, consoleName, dismissFlash, session } from '../store';

/**
 * The console chrome: which app you are in, that app's nav, the flash messages
 * and the routed screen.
 *
 * The shell knows nothing about any app. The active app is whatever the matched
 * route says it belongs to; its nav, its chrome buttons and its own setup banner
 * all come from that app's manifest. On the launcher there is no active app, so
 * the chrome is just the console.
 */
const route = useRoute();

const activeApp = computed(() => (typeof route.meta.app === 'string' ? appById(route.meta.app) : null));

const manifest = computed(() => (activeApp.value ? manifestFor(activeApp.value.id) : null));

/** `requires` is an ability from the bootstrap payload; no entry means always. */
const permitted = (entry) => typeof entry.requires !== 'string' || can(entry.requires);

const links = computed(() => (manifest.value?.nav ?? []).filter(permitted));

const actions = computed(() => (manifest.value?.actions ?? []).filter(permitted));

/** The active app's own banner, if it ships one. */
const notice = computed(() => (manifest.value?.notice ? defineAsyncComponent(manifest.value.notice) : null));

const flashClasses = (kind) =>
    ({
        success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
        error: 'border-rose-200 bg-rose-50 text-rose-900',
        info: 'border-sky-200 bg-sky-50 text-sky-900',
    })[kind] ?? 'border-slate-200 bg-slate-50 text-slate-900';

const actionClasses = (variant) =>
    variant === 'primary'
        ? 'rounded-md bg-slate-900 px-3 py-1.5 font-medium text-white hover:bg-slate-700'
        : 'rounded-md px-3 py-1.5 font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50';
</script>

<template>
    <div class="min-h-full">
        <nav class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 sm:px-6">
                <!-- The launcher is where the apps are, so the brand goes there
                     rather than to any one app's dashboard. -->
                <RouterLink to="/" class="flex items-center gap-2 font-semibold tracking-tight">
                    <span
                        class="inline-flex h-6 w-6 items-center justify-center rounded bg-slate-900 text-xs font-bold text-white"
                        >wo</span
                    >
                    {{ consoleName }}
                </RouterLink>

                <div v-if="activeApp" class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                    <span class="text-slate-300">/</span>
                    <RouterLink :to="activeApp.path" class="font-medium text-slate-900">
                        {{ activeApp.name }}
                    </RouterLink>

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
                        v-for="action in actions"
                        :key="action.to"
                        :to="action.to"
                        :class="actionClasses(action.variant)"
                    >
                        {{ action.label }}
                    </RouterLink>

                    <!-- Central access management: deliberately not an app, because
                         it is how the apps are handed out. -->
                    <RouterLink v-if="can('manage_users')" to="/access" class="text-slate-600 hover:text-slate-900">
                        Access
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
            <component :is="notice" v-if="notice" />

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
